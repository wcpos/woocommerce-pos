<?php
/**
 * Settings Trait
 *
 * - Used by Admin Settings and API Settings classes
 */

namespace WCPOS\WooCommercePOS\Traits;

use WC_Payment_Gateways;

trait Settings {

	/**
	 *
	 */
	private $default_settings = array(
		'general'  => array(
			'pos_only_products'           => false,
			'decimal_qty'                 => false,
			'force_ssl'                   => true,
			'default_customer'            => 0,
			'default_customer_is_cashier' => false,
			'barcode_field'               => '_sku',
			'generate_username'           => true,
		),
		'checkout' => array(
			'order_status'       => 'wc-completed',
			'admin_emails'       => true,
			'customer_emails'    => true,
			'auto_print_receipt' => false,
			'default_gateway'    => 'pos_cash',
			'gateways'           => array(),
		),
	);

	/**
	 * @var array
	 */
	private $caps = array(
		'wcpos' => array(
			'access_woocommerce_pos',  // pos frontend
			'manage_woocommerce_pos', // pos admin
		),
		'wc'    => array(
			'create_users',
			'edit_others_products',
			'edit_product',
			'edit_published_products',
			'edit_users',
			'list_users',
			'publish_shop_orders',
			'read_private_products',
			'read_private_shop_coupons',
			'read_private_shop_orders',
		),
		'wp'    => array(
			'read', // wp-admin access
		),
	);

	/**
	 * @return array
	 */
	public function get_all_settings() {
		$data = array(
			'general'  => $this->get_settings( 'general' ),
			'checkout' => $this->get_settings( 'checkout' ),
			'access'   => $this->get_access_settings(),
		);

		return apply_filters( 'woocommerce_pos_settings', $data );
	}

	/**
	 * @param string $group
	 *
	 * @return array
	 */
	public function get_settings( $group ) {
		$settings = wp_parse_args(
			array_intersect_key(
				woocommerce_pos_get_settings( $group, null, array() ),
				$this->default_settings[ $group ]
			),
			$this->default_settings[ $group ]
		);

		if ( 'checkout' == $group ) {
			$settings['gateways'] = $this->get_gateways();
		}

		return $settings;
	}

	/**
	 *
	 */
	public function get_gateways() {
		$ordered_gateways = array();
		$gateways         = WC_Payment_Gateways::instance()->payment_gateways;

		foreach ( $gateways as $gateway ) {
			array_push(
				$ordered_gateways,
				array(
					'id'          => $gateway->id,
					'title'       => $gateway->title,
					'description' => $gateway->description,
					'enabled'     => false,

				)
			);
		}

		return $ordered_gateways;
	}

	/**
	 *
	 */
	public function get_access_settings() {
		global $wp_roles;
		$role_caps = array();

		$roles = $wp_roles->roles;
		if ( $roles ) :
			foreach ( $roles as $slug => $role ) :
				$role_caps[ $slug ] = array(
					'name'         => $role['name'],
					'capabilities' => array(
						'wcpos' => array_intersect_key(
							array_merge( array_fill_keys( $this->caps['wcpos'], false ), $role['capabilities'] ),
							array_flip( $this->caps['wcpos'] )
						),
						'wc'    => array_intersect_key(
							array_merge( array_fill_keys( $this->caps['wc'], false ), $role['capabilities'] ),
							array_flip( $this->caps['wc'] )
						),
						'wp'    => array_intersect_key(
							array_merge( array_fill_keys( $this->caps['wp'], false ), $role['capabilities'] ),
							array_flip( $this->caps['wp'] )
						),
					),
				);
			endforeach;
		endif;

		return $role_caps;
	}
}
