<?php

namespace WCPOS\WooCommercePOS\API;

use WP_REST_Request;

class Payment_Gateways {
	private $request;

	/**
	 * Payment Gateways constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;

		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'check_permissions' ), 10, 4 );
	}

	/**
	 * Authorize payment_gateways API (read only) for cashiers.
	 *
	 * @param mixed $permission
	 * @param mixed $context
	 * @param mixed $object_id
	 * @param mixed $object
	 */
	public function check_permissions( $permission, $context, $object_id, $object ) {
		if ( ! $permission && 'payment_gateways' === $object && 'read' === $context ) {
			$permission = current_user_can( 'publish_shop_orders' );
		}

		return $permission;
	}

	/**
	 * Returns array of all gateway ids, titles.
	 *
	 * @param array $fields
	 *
	 * @return array|void
	 */
	public function get_all_posts( array $fields = array() ) {
		$pos_gateways         = Settings::get_setting( 'checkout', 'gateways' );
		$enabled_pos_gateways = array();

		for ( $i = 0; $i < \count( $pos_gateways ); $i ++ ) {
			if ( $pos_gateways[ $i ]['enabled'] ) {
				$gateway = $pos_gateways[ $i ];

				$gateway['order']       = $i;
				$enabled_pos_gateways[] = $gateway;
			}
		}

		return $enabled_pos_gateways;
	}
}
