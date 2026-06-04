<?php
/**
 * Creates cloud print jobs from WooCommerce order events.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * Cloud_Print_Trigger_Service class.
 */
class Cloud_Print_Trigger_Service {
	const OPTION = 'woocommerce_pos_settings_cloud_print';

	/**
	 * Cron hook used to submit a PrintNode job out-of-band (never on checkout).
	 */
	const CRON_SUBMIT = 'wcpos_cloud_print_submit';

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
	 * Constructor — hook order events.
	 */
	public function __construct() {
		$this->jobs     = new Print_Job_Service();
		$this->registry = new Cloud_Print_Registry();
		add_action( 'woocommerce_new_order', array( $this, 'handle_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order' ), 20, 1 );
	}

	/**
	 * Create jobs for an order according to the configured assignments.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$settings    = get_option( self::OPTION, array() );
		$assignments = isset( $settings['assignments'] ) && \is_array( $settings['assignments'] ) ? $settings['assignments'] : array();

		/**
		 * Filter the cloud-print assignments for an order. Pro uses this to
		 * substitute per-outlet assignments based on the order's store.
		 *
		 * @param array     $assignments Global assignments.
		 * @param \WC_Order $order       The order being processed.
		 */
		$assignments = apply_filters( 'woocommerce_pos_cloud_print_assignments', $assignments, $order );
		if ( ! \is_array( $assignments ) ) {
			$assignments = array();
		}

		if ( empty( $assignments ) ) {
			return;
		}

		$is_pos = 'woocommerce-pos' === $order->get_created_via();

		foreach ( $assignments as $assignment ) {
			if ( empty( $assignment['printer_id'] ) || empty( $assignment['template_id'] ) ) {
				continue;
			}
			$scope = isset( $assignment['scope'] ) ? (string) $assignment['scope'] : 'every';
			if ( ! $this->scope_matches( $scope, $is_pos ) ) {
				continue;
			}
			if ( $this->already_queued( $order->get_id(), (string) $assignment['printer_id'], (string) $assignment['template_id'] ) ) {
				continue;
			}

			$printer = $this->registry->get_printer( (string) $assignment['printer_id'] );
			if ( empty( $printer ) ) {
				continue;
			}
			$provider = (string) ( $printer['provider'] ?? '' );

			$template_id = (string) $assignment['template_id'];
			$template    = Print_Job_Service::load_template( $template_id );
			if ( null === $template ) {
				continue;
			}

			$job_id = self::enqueue_order_job(
				$this->jobs,
				(string) $assignment['printer_id'],
				$printer,
				$order->get_id(),
				$template_id,
				$template
			);
			if ( 0 === $job_id ) {
				Logger::log(
					sprintf(
						'Cloud print: skipping assignment for printer "%s" — template "%s" is not printable on provider "%s".',
						(string) $assignment['printer_id'],
						$template_id,
						$provider
					)
				);
			}
		}
	}

	/**
	 * Enqueue a print job for an order + template, deriving the wire format from
	 * the printer's provider. Shared by the order-event trigger and the manual
	 * print-jobs endpoint so the two cannot drift.
	 *
	 * For PrintNode the job's submit event is scheduled out-of-band (PrintNode
	 * does not poll). For polling providers (Star/Epson) the printer fetches the
	 * job on its next poll, so no submit is scheduled.
	 *
	 * @param Print_Job_Service $jobs        Job store.
	 * @param string            $printer_id  Registered printer id.
	 * @param array             $printer     Registered printer config.
	 * @param int               $order_id    Order id to render.
	 * @param string            $template_id Template id (numeric) or virtual slug.
	 * @param array             $template    Loaded template array.
	 *
	 * @return int Created job id, or 0 when the template is not printable on the provider.
	 */
	public static function enqueue_order_job( Print_Job_Service $jobs, string $printer_id, array $printer, int $order_id, string $template_id, array $template ): int {
		$provider = (string) ( $printer['provider'] ?? '' );

		if ( 'printnode' === $provider ) {
			$fmt = ( new Print_Format_Resolver() )->resolve( $printer, $template );
			if ( '' === $fmt['kind'] ) {
				return 0;
			}

			$job_id = $jobs->create(
				array(
					'printer_id'   => $printer_id,
					'order_id'     => $order_id,
					'template_id'  => $template_id,
					'content_type' => $fmt['content_type'],
					'pn_kind'      => $fmt['kind'],
				)
			);
			if ( $job_id > 0 ) {
				wp_schedule_single_event( time(), self::CRON_SUBMIT, array( $job_id ) );
			}

			return $job_id;
		}

		$engine = (string) ( $template['engine'] ?? '' );
		$wire   = Provider::wire_format( $provider, $engine );
		if ( null === $wire ) {
			return 0;
		}

		$job_id = $jobs->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => Provider::content_type( $provider ),
				'order_id'     => $order_id,
				'template_id'  => $template_id,
			)
		);

		// Push providers (e.g. Star Online) don't poll us; submit out-of-band.
		if ( $job_id > 0 && Provider::requires_submit( $provider ) ) {
			wp_schedule_single_event( time(), self::CRON_SUBMIT, array( $job_id ) );
		}

		return $job_id;
	}

	/**
	 * Whether an assignment scope applies to this order origin.
	 *
	 * @param string $scope  every|pos|online.
	 * @param bool   $is_pos Whether the order was created via the POS.
	 */
	private function scope_matches( string $scope, bool $is_pos ): bool {
		if ( 'every' === $scope ) {
			return true;
		}
		if ( 'pos' === $scope ) {
			return $is_pos;
		}
		if ( 'online' === $scope ) {
			return ! $is_pos;
		}

		return false;
	}

	/**
	 * Guard against duplicate jobs for the same order+printer+template.
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $printer_id  Printer ID.
	 * @param string $template_id Template ID.
	 */
	private function already_queued( int $order_id, string $printer_id, string $template_id ): bool {
		$existing = $this->jobs->query(
			array(
				'printer_id'  => $printer_id,
				'order_id'    => $order_id,
				'template_id' => $template_id,
				'limit'       => 1,
			)
		);

		return ! empty( $existing );
	}
}
