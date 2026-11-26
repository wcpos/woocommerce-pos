<?php
/**
 * Prevent WooCommerce's refund routes from sending hand-recorded payments to a provider.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;

\defined( 'ABSPATH' ) || die;

/**
 * Refuse provider refunds before WooCommerce creates the refund. The REST hook
 * leaves WP Admin's admin-ajax refund flow untouched.
 */
final class Unverified_Refund_Guard {
	/** Register on matched REST routes, before the refund callback runs. */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'guard' ), 10, 3 );
	}

	/**
	 * Refuse a refund if the order's gateway has an effective unverified payment.
	 *
	 * @param mixed            $response Earlier response or error.
	 * @param array            $handler  Matched route handler.
	 * @param \WP_REST_Request $request  Request being dispatched.
	 * @return mixed Original response or a refund refusal.
	 */
	public static function guard( $response, $handler, $request ) {
		if ( null !== $response || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! preg_match( '#^/wc/v[23]/orders/\d+/refunds/?$#i', $route ) && ! preg_match( '#^/wc/v4/refunds/?$#i', $route ) ) {
			return $response;
		}

		$api     = $request['api_refund'];
		$reaches = is_bool( $api ) ? $api : true;
		if ( ! $reaches || ! isset( $handler['permission_callback'] ) || true !== call_user_func( $handler['permission_callback'], $request ) ) {
			return $response;
		}

		$order = wc_get_order( absint( $request['order_id'] ) );
		if ( ! $order instanceof \WC_Order ) {
			return $response;
		}

		foreach ( Ledger::effective_rows( Ledger::instance()->read( $order ) ) as $row ) {
			if ( true === ( $row['unverified'] ?? null ) && (string) ( $row['method_id'] ?? '' ) === $order->get_payment_method() ) {
				return new \WP_Error(
					'wcpos_refund_unverified_payment',
					__( 'This payment was recorded by hand, so its payment provider never took it and cannot refund it. Refund it to another payment method.', 'woocommerce-pos' ),
					array(
						'status'     => 409,
						'reason'     => 'unverified',
						'payment_id' => (string) $row['id'],
					)
				);
			}
		}

		return $response;
	}
}
