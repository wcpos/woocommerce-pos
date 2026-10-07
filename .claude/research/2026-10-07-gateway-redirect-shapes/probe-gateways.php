<?php
/**
 * Plugin Name: WCPOS probe gateways (local verification only)
 *
 * Two minimal WC_Payment_Gateway shapes, no vendor logic:
 *  - probe_hosted:   returns success + an OFF-SITE redirect (hosted checkout shape).
 *  - probe_paylater: returns success + get_return_url( $order ) (pay-later shape).
 * Neither touches the order status or calls payment_complete().
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	class WCPOS_Probe_Hosted extends WC_Payment_Gateway {
		public function __construct() {
			$this->id           = 'probe_hosted';
			$this->method_title = 'Probe hosted';
			$this->title        = 'Probe hosted';
			$this->enabled      = 'yes';
		}
		public function is_available() { return true; }
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$order->add_order_note( 'probe_hosted: redirecting off-site' );
			return array( 'result' => 'success', 'redirect' => 'https://hosted.example.test/pay?sid=' . $order_id );
		}
	}
	class WCPOS_Probe_Paylater extends WC_Payment_Gateway {
		public function __construct() {
			$this->id           = 'probe_paylater';
			$this->method_title = 'Probe pay later';
			$this->title        = 'Probe pay later';
			$this->enabled      = 'yes';
		}
		public function is_available() { return true; }
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$order->add_order_note( 'probe_paylater: finished, no money' );
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}
	}
	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WCPOS_Probe_Hosted';
		$gateways[] = 'WCPOS_Probe_Paylater';
		return $gateways;
	} );
} );
