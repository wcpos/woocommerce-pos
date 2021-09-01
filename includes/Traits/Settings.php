<?php
/**
 * Settings Trait
 *
 * - Used by Admin Settings and API Settings classes
 */

namespace WCPOS\WooCommercePOS\Traits;

use WC_Payment_Gateways;
use WP_Error;

trait Settings {

	/* @var string The db prefix for WP Options table */
	private static $db_prefix = 'woocommerce_pos_settings_';

	/**
	 *
	 */
	private static $default_settings = array(
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
	private static $caps = array(
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
	 * Get settings by group and key
	 */
	public static function get_setting( $group, $key ) {
		$settings = self::get_settings( $group );

		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		return new WP_Error(
			'woocommerce_pos_settings_error',
			'Settings key not found'
		);
	}

	/**
	 * @param string $group
	 *
	 * @return array
	 */
	public static function get_settings( $group ) {
		$name     = self::$db_prefix . $group;
		$settings = self::normalize_settings( $group, get_option( $name, array() ) );

		if ( 'checkout' == $group ) {
			$settings['gateways'] = self::get_gateways();
		}

		return $settings;
	}

	/**
	 *
	 */
	public static function normalize_settings( $group, $value ) {
		return wp_parse_args(
			array_intersect_key(
				$value,
				self::$default_settings[ $group ]
			),
			self::$default_settings[ $group ]
		);
	}

	/**
	 * Returns the pos gateways settings, but also merges default settings
	 * - note: gateways may be added/removed creating stale settings
	 *
	 * @return array
	 */
	public static function get_gateways() {
		$available_gateways = array();
		$gateways           = WC_Payment_Gateways::instance()->payment_gateways();
		$checkout_settings  = get_option( self::$db_prefix . 'checkout' );
		$gateway_settings   = array();

		// map gateway settings so that id is key
		if ( isset( $checkout_settings['gateways'] ) && is_array( $checkout_settings['gateways'] ) ) {
			foreach ( $checkout_settings['gateways'] as $g ) {
				$gateway_settings[ $g['id'] ] = $g;
			}
		} else {
			// case where there are no previous gateway settings
			$default_gateways = array( 'pos_cash', 'pos_card' );
			foreach ( $default_gateways as $id ) {
				if ( isset( $gateways[ $id ] ) ) {
					$gateway_settings[ $id ] = array(
						'id'          => $id,
						'title'       => $gateways[ $id ]->title,
						'description' => $gateways[ $id ]->description,
						'enabled'     => true,
					);
				}
			}
		}

		$ordered_gateways = array_merge( array_flip( array_keys( $gateway_settings ) ), $gateways );

		foreach ( $ordered_gateways as $gateway ) {
			if ( isset( $gateway_settings[ $gateway->id ] ) ) {
				array_push( $available_gateways, $gateway_settings[ $gateway->id ] );
			} else {
				array_push(
					$available_gateways,
					array(
						'id'          => $gateway->id,
						'title'       => $gateway->title,
						'description' => $gateway->description,
						'enabled'     => false,
					)
				);
			}
		}

		return $available_gateways;
	}

	/**
	 * @return array
	 */
	public static function get_all_settings() {
		$data = array(
			'general'  => self::get_settings( 'general' ),
			'checkout' => self::get_settings( 'checkout' ),
			'access'   => self::get_access_settings(),
		);

		return apply_filters( 'woocommerce_pos_settings', $data );
	}

	/**
	 *
	 */
	public static function get_access_settings() {
		global $wp_roles;
		$role_caps = array();

		$roles = $wp_roles->roles;
		if ( $roles ) :
			foreach ( $roles as $slug => $role ) :
				$role_caps[ $slug ] = array(
					'name'         => $role['name'],
					'capabilities' => array(
						'wcpos' => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wcpos'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wcpos'] )
						),
						'wc'    => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wc'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wc'] )
						),
						'wp'    => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wp'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wp'] )
						),
					),
				);
			endforeach;
		endif;

		return $role_caps;
	}

	/**
	 *
	 */
	public static function update_setting( $group, $key, $value ) {
		$settings = self::get_settings( $group );
		if ( $settings ) {
			$settings[ $key ] = $value;

			return self::update_settings( $group, $settings );
		}

		return false;
	}

	/**
	 *
	 */
	public static function update_settings( $group, $value ) {
		$name     = self::$db_prefix . $group;
		$settings = self::normalize_settings( $group, $value );

		return update_option( $name, $settings );
	}
}
