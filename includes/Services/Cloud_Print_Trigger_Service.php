<?php
/**
 * Creates cloud print jobs from WooCommerce order events.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Cloud_Print_Trigger_Service class.
 */
class Cloud_Print_Trigger_Service {
	const OPTION = 'woocommerce_pos_settings_cloud_print';

	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Constructor — hook order events.
	 */
	public function __construct() {
		$this->jobs = new Print_Job_Service();
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

		if ( empty( $assignments ) ) {
			return;
		}

		$is_pos = 'woocommerce-pos' === $order->get_created_via();

		foreach ( $assignments as $assignment ) {
			if ( empty( $assignment['printer_id'] ) || empty( $assignment['format'] ) ) {
				continue;
			}
			$scope = isset( $assignment['scope'] ) ? (string) $assignment['scope'] : 'every';
			if ( ! $this->scope_matches( $scope, $is_pos ) ) {
				continue;
			}
			if ( $this->already_queued( $order->get_id(), (string) $assignment['printer_id'] ) ) {
				continue;
			}

			$this->jobs->create(
				array(
					'printer_id'   => (string) $assignment['printer_id'],
					'content_type' => 'epos-xml' === $assignment['format'] ? 'application/xml' : 'application/octet-stream',
					'order_id'     => $order->get_id(),
					'format'       => (string) $assignment['format'],
				)
			);
		}
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
	 * Guard against duplicate jobs for the same order+printer.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $printer_id Printer ID.
	 */
	private function already_queued( int $order_id, string $printer_id ): bool {
		foreach (
			$this->jobs->query(
				array(
					'printer_id' => $printer_id,
					'limit'      => -1,
				)
			) as $job
		) {
			if ( (int) $job['order_id'] === $order_id ) {
				return true;
			}
		}

		return false;
	}
}
