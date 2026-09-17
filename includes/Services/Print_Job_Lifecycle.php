<?php
/**
 * Shared polling claim conversation.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Interfaces\Poll_Provider_Adapter_Interface;
use WCPOS\WooCommercePOS\Logger;

/**
 * Protocol adapters own bytes; this service owns the order of job transitions.
 */
class Print_Job_Lifecycle {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;
	/**
	 * Printer registry.
	 *
	 * @var Cloud_Print_Registry
	 */
	private $registry;
	/**
	 * Explicit adapter.
	 *
	 * @var Poll_Provider_Adapter_Interface|null
	 */
	private $adapter;

	/**
	 * Construct the polling conversation.
	 *
	 * @param Print_Job_Service|null               $jobs     Job store.
	 * @param Cloud_Print_Registry|null            $registry Printer registry.
	 * @param Poll_Provider_Adapter_Interface|null $adapter  Explicit protocol adapter.
	 */
	public function __construct( ?Print_Job_Service $jobs = null, ?Cloud_Print_Registry $registry = null, ?Poll_Provider_Adapter_Interface $adapter = null ) {
		$this->jobs = $jobs ?? new Print_Job_Service();
		$this->registry = $registry ?? new Cloud_Print_Registry();
		$this->adapter = $adapter;
	}

	/**
	 * Run the ordered job lifecycle.
	 *
	 * @param string $provider_key Protocol selected by the route.
	 * @param array  $printer      Registered printer.
	 * @param array  $poll         Parsed plain-data request.
	 * @return array{status:int, headers:array, body:string|array}
	 */
	public function handle_poll( string $provider_key, array $printer, array $poll ): array {
		$adapter = $this->adapter ?? Provider::poll_adapter( $provider_key );
		$printer_id = $printer['id'];
		$this->registry->record_seen( $printer_id );
		$this->jobs->release_stale_claims( $printer_id );

		if ( 'result' === $poll['phase'] ) {
			$match = $adapter->match_result( $poll );
			if ( null !== $match['job_id'] ) {
				$job = $this->jobs->get( $match['job_id'] );
			} else {
				$job = $this->jobs->find_active_claim( $printer_id );
				$unconfirmed = $this->jobs->find_unconfirmed( $printer_id );
				if ( null !== $job && null !== $unconfirmed ) {
					Logger::warning( sprintf( 'Printer "%s": result recorded against claimed job %d while unconfirmed job %d is still awaiting one.', $printer_id, (int) $job['id'], (int) $unconfirmed['id'] ) );
				}
				$job = $job ?? $unconfirmed;
			}
			if ( null !== $match['job_id'] && ( null === $job || $printer_id !== $job['printer_id'] ) ) {
				return $adapter->advertise( $printer, $this->missing_job( $poll, $printer_id, $match['job_id'] ), null );
			}
			if ( null !== $job ) {
				$result = $adapter->interpret_result( $poll );
				$this->jobs->record_printer_result( (int) $job['id'], $result['ok'] );
				if ( ! $result['ok'] ) {
					$this->log_printer_failure( $poll, $printer_id, $result, (int) $job['id'] );
				}
			}
			return $adapter->advertise( $printer, $poll, null );
		}

		if ( isset( $poll['answers'] ) ) {
			$this->registry->record_capabilities( $printer_id, $poll['answers'], $poll['status_code'] );
		}
		$job = null;
		if ( 'fetch' === $poll['phase'] && isset( $poll['token'] ) ) {
			$job = $this->jobs->get( $poll['token'] );
			if ( null === $job || $printer_id !== $job['printer_id'] ) {
				return $adapter->advertise( $printer, $this->missing_job( $poll, $printer_id, $poll['token'] ), null );
			}
		} elseif ( empty( $poll['busy'] ) && null === $this->jobs->find_active_claim( $printer_id ) ) {
			$job = $this->jobs->next_pending( $printer_id );
		}
		if ( null !== $job && ! empty( $poll['needs_capabilities'] ) ) {
			$printer['encodings'] = $this->registry->get_capabilities( $printer_id )['encodings'];
		}
		if ( 'advertise' === $poll['phase'] ) {
			$poll['request_capabilities'] = isset( $poll['answers'] ) && $this->registry->should_request_capabilities( $printer_id );
			$response = $adapter->advertise( $printer, $poll, $job );
			if ( $poll['request_capabilities'] ) {
				$this->registry->record_capability_request( $printer_id );
			}
			return $response;
		}
		if ( null === $job ) {
			return $adapter->advertise( $printer, $poll, null );
		}
		$negotiated = $adapter->negotiate( $printer, $poll, $job );
		if ( isset( $negotiated['response'] ) ) {
			return $negotiated['response'];
		}
		if ( ! $this->jobs->try_claim( (int) $job['id'] ) ) {
			return $adapter->advertise( $printer, $poll, null );
		}
		$poll['media_type'] = $negotiated['media_type'];
		$render = $this->jobs->render_job( $job, $poll['media_type'] );
		if ( '' === $render['body'] ) {
			Logger::error( sprintf( '%s: print job %d rendered an empty payload for printer "%s"; nothing was sent to the printer.', $poll['route'], (int) $job['id'], $printer_id ) );
			update_post_meta( (int) $job['id'], Print_Job_Service::META_ERROR, 'empty_rendered_payload' );
			$this->jobs->set_status( (int) $job['id'], Print_Job_Service::STATUS_FAILED );
		}
		return $adapter->deliver( $job, $render, $poll );
	}

	/**
	 * Log a missing job and pass a neutral intent to the adapter.
	 *
	 * @param array  $poll       Parsed request.
	 * @param string $printer_id Printer ID.
	 * @param int    $job_id     Missing or foreign job token.
	 * @return array Parsed request with a neutral not-found intent.
	 */
	private function missing_job( array $poll, string $printer_id, int $job_id ): array {
		Logger::warning( sprintf( '%s: print job "%d" was not found for printer "%s".', $poll['route'], $job_id, $printer_id ) );
		$poll['intent'] = 'not_found';
		return $poll;
	}

	/**
	 * Persist and log the printer failure.
	 *
	 * @param array  $poll       Parsed request.
	 * @param string $printer_id Printer ID.
	 * @param array  $result     Interpreted vendor result.
	 * @param int    $job_id     Job ID.
	 */
	private function log_printer_failure( array $poll, string $printer_id, array $result, int $job_id ): void {
		$reason = $result['code'] . $result['detail'];
		if ( '' !== $reason ) {
			update_post_meta( $job_id, Print_Job_Service::META_ERROR, sanitize_text_field( $reason ) );
		}
		Logger::error( sprintf( '%s: printer "%s" reported failure code "%s"%s for print job %d.', $poll['route'], $printer_id, $result['code'], $result['detail'], $job_id ) );
	}
}
