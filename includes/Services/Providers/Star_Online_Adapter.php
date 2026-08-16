<?php
/**
 * StarIO.Online provider adapter.
 *
 * @package WCPOS\WooCommercePOS\Services\Providers
 */

namespace WCPOS\WooCommercePOS\Services\Providers;

use WCPOS\WooCommercePOS\Interfaces\Push_Provider_Adapter_Interface;
use WCPOS\WooCommercePOS\Services\Star_Online_Client;

/**
 * Star_Online_Adapter class.
 */
class Star_Online_Adapter implements Push_Provider_Adapter_Interface {
	/**
	 * Return the Star Document Markup content type.
	 *
	 * @return string
	 */
	public function content_type(): string {
		return 'text/vnd.star.markup';
	}

	/**
	 * Resolve Star Document Markup format data.
	 *
	 * @param array $printer  Printer configuration.
	 * @param array $template Template configuration.
	 *
	 * @return array{kind:string, content_type:string}
	 */
	public function format( array $printer, array $template ): array {
		if ( 'thermal' !== (string) ( $template['engine'] ?? '' ) ) {
			return array(
				'kind' => '',
				'content_type' => '',
			);
		}

		return array(
			'kind' => 'star-markup',
			'content_type' => $this->content_type(),
		);
	}

	/**
	 * Build a Star Document Markup diagnostic.
	 *
	 * @param string $printer_name Printer display name.
	 *
	 * @return array{content_type:string, payload:string}
	 */
	public function diagnostic( string $printer_name ): array {
		$name   = str_replace( array( '[', ']' ), array( '[[', ']]' ), $printer_name );
		$markup = '[align: middle][bold: on]WCPOS[bold: off]' . "\nCloud Print Test\n[align: left]";
		$markup .= 'Printer: ' . $name . "\nDate: " . gmdate( 'Y-m-d H:i' ) . "\n";
		$markup .= "If you can read this, printing works!\n[feed][cut]";

		return array(
			'content_type' => $this->content_type(),
			'payload' => base64_encode( $markup ),
		);
	}

	/**
	 * Resolve Star Online live status with the existing transient cache contract.
	 *
	 * @param array $printer Printer configuration.
	 * @param array $context Runtime status context.
	 *
	 * @return string
	 */
	public function status( array $printer, array $context ): string {
		$key    = 'wcpos_cloud_print_star_status_' . md5( (string) $printer['id'] );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$api_key   = (string) ( $printer['star_api_key'] ?? '' );
		$url       = (string) ( $printer['star_cloudprnt_url'] ?? '' );
		$device_id = (string) ( $printer['star_device_id'] ?? '' );
		$api_base  = Star_Online_Client::api_base_from_cloudprnt_url( $url );
		$group     = Star_Online_Client::group_from_cloudprnt_url( $url );
		$status    = '' === $api_key || null === $api_base || '' === $group || '' === $device_id
			? 'unknown'
			: ( new Star_Online_Client( $api_base, $api_key ) )->device_state( $group, $device_id );
		set_transient( $key, $status, (int) $context['cache_ttl'] );

		return $status;
	}

	/**
	 * Submit a Star Online job.
	 *
	 * @param array  $printer Printer configuration.
	 * @param array  $job     Print job data.
	 * @param string $payload Rendered payload bytes.
	 * @param string $title   Provider job title.
	 *
	 * @return array{success:bool, retryable:bool, error:string, external_job_id:string, drawer_error:string}
	 */
	public function submit( array $printer, array $job, string $payload, string $title ): array {
		$api_key   = (string) ( $printer['star_api_key'] ?? '' );
		$url       = (string) ( $printer['star_cloudprnt_url'] ?? '' );
		$device_id = (string) ( $printer['star_device_id'] ?? '' );
		$api_base  = Star_Online_Client::api_base_from_cloudprnt_url( $url );
		$group     = Star_Online_Client::group_from_cloudprnt_url( $url );
		if ( '' === $api_key || null === $api_base || '' === $group || '' === $device_id ) {
			return $this->failure( 'Cloud print: Star Online printer is misconfigured.', false );
		}
		if ( '' === $payload ) {
			return $this->failure( 'Cloud print: Star Online job produced no printable content.', false );
		}

		$result = ( new Star_Online_Client( $api_base, $api_key ) )->submit_job(
			$group,
			$device_id,
			$title,
			$this->content_type(),
			$payload
		);
		if ( is_wp_error( $result ) ) {
			return $this->failure( $result->get_error_message(), true );
		}

		return array(
			'success' => true,
			'retryable' => false,
			'error' => '',
			'external_job_id' => (string) $result['id'],
			'drawer_error' => '',
		);
	}

	/**
	 * Build the stable submit failure shape.
	 *
	 * @param string $error     Provider error message.
	 * @param bool   $retryable Whether the service should retry.
	 *
	 * @return array{success:bool, retryable:bool, error:string, external_job_id:string, drawer_error:string}
	 */
	private function failure( string $error, bool $retryable ): array {
		return array(
			'success' => false,
			'retryable' => $retryable,
			'error' => $error,
			'external_job_id' => '',
			'drawer_error' => '',
		);
	}
}
