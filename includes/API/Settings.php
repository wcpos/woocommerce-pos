<?php

namespace WCPOS\WooCommercePOS\API;

use Closure;
use WC_Payment_Gateways;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use function in_array;
use function is_array;
use function is_bool;
use function is_integer;
use function is_string;

class Settings extends Settings_Controller {


	/**
	 * Prefix for the $wpdb->options table.
	 *
	 * @var string
	 */
	protected static $db_prefix = 'woocommerce_pos_settings_';

	protected static $default_settings = array(
		'general' => array(
			'pos_only_products' => false,
			'decimal_qty' => false,
			'force_ssl' => true,
			'default_customer' => 0,
			'default_customer_is_cashier' => false,
			'barcode_field' => '_sku',
			'generate_username' => true,
		),
		'checkout' => array(
			'order_status' => 'wc-completed',
			'admin_emails' => true,
			'customer_emails' => true,
		),
		'payment_gateways' => array(
			'default_gateway' => 'pos_cash',
			'gateways' => array(
				'pos_cash' => array(
					'order' => 0,
					'enabled' => true,
				),
				'pos_card' => array(
					'order' => 1,
					'enabled' => true,
				),
			),
		),
		'license' => array(),
	);
	/**
	 * @var array
	 */
	private static $caps = array(
		'wcpos' => array(
			'access_woocommerce_pos',  // pos frontend
			'manage_woocommerce_pos', // pos admin
		),
		'wc' => array(
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
		'wp' => array(
			'read', // wp-admin access
		),
	);

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Settings constructor.
	 */
	public function __construct() {     }

	/**
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_items' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_general_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general/barcodes',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_barcodes' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/general',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_general_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args' => $this->get_general_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_checkout_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout/order-statuses',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_order_statuses' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_checkout_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args' => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_payment_gateways_settings' ),
				'permission_callback' => array( $this, 'update_permission_check' ),
				'args' => $this->get_checkout_endpoint_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_access_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/access',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_access_settings' ),
				'permission_callback' => array( $this, 'access_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/license',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_license_settings' ),
				'permission_callback' => array( $this, 'read_permission_check' ),
			)
		);
	}

	/**
	 * @return Closure[][]
	 */
	public function get_general_endpoint_args(): array {
		return array(
			'pos_only_products' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'decimal_qty' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'force_ssl' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'default_customer' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_integer( $param );
				},
			),
			'default_customer_is_cashier' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'barcode_field' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'generate_username' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
		);
	}

	/**
	 * @return Closure[][]
	 */
	public function get_checkout_endpoint_args(): array {
		return array(
			'order_status' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'admin_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'customer_emails' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'auto_print_receipt' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_bool( $param );
				},
			),
			'default_gateway' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_string( $param );
				},
			),
			'gateways' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return is_array( $param );
				},
			),
		);
	}

	/**
	 *
	 */
	public function get_license_settings() {
		$license_settings = array_replace_recursive(
			self::$default_settings['license'],
			get_option( self::$db_prefix . 'license', array() )
		);

		/**
		 * Filters the license settings.
		 *
		 * @param {array} $settings
		 *
		 * @returns {array} $settings
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_license_settings
		 */
		return apply_filters( 'woocommerce_pos_license_settings', $license_settings );
	}

	/**
	 * @return array
	 */
	public function get_barcodes(): array {
		global $wpdb;

		$result = $wpdb->get_col(
			"
			SELECT DISTINCT(pm.meta_key)
			FROM $wpdb->postmeta AS pm
			JOIN $wpdb->posts AS p
			ON p.ID = pm.post_id
			WHERE p.post_type IN ('product', 'product_variation')
			ORDER BY pm.meta_key
			"
		);

		// maybe add custom barcode field
		$custom_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		if ( ! empty( $custom_field ) ) {
			$result[] = $custom_field;
		}

		sort( $result );

		return array_unique( $result );
	}

	/**
	 * @return array
	 */
	public function get_order_statuses() {
		$order_statuses = wc_get_order_statuses();
		$order_statuses = array_map( 'wc_get_order_status_name', $order_statuses );

		return $order_statuses;
	}

	/**
	 *
	 */
	public function get_payment_gateways_settings() {
		// Note: I need to re-init the gateways here to pass the tests, but it seems to work fine in the app.
		WC_Payment_Gateways::instance()->init();
		$installed_gateways = WC_Payment_Gateways::instance()->payment_gateways();
		$gateways_settings = array_replace_recursive(
			self::$default_settings['payment_gateways'],
			get_option( self::$db_prefix . 'payment_gateways', array() )
		);

		// NOTE - gateways can be installed and uninstalled, so we need to assume the settings data is stale
		$response = array(
			'default_gateway' => $gateways_settings['default_gateway'],
			'gateways' => array(),
		);

		// loop through installed gateways and merge with saved settings
		foreach ( $installed_gateways as $id => $gateway ) {
			// sanity check for gateway class
			if ( ! is_a( $gateway, 'WC_Payment_Gateway' ) || 'pre_install_woocommerce_payments_promotion' === $id ) {
				continue;
			}
			$response['gateways'][ $id ] = array_replace_recursive(
				array(
					'id' => $gateway->id,
					'title' => $gateway->title,
					'description' => $gateway->description,
					'enabled' => false,
					'order' => 999,
				),
				isset( $gateways_settings['gateways'][ $id ] ) ? $gateways_settings['gateways'][ $id ] : array()
			);
		}

		/**
		 * Filters the payment gateways settings.
		 *
		 * @param {array} $settings
		 *
		 * @returns {array} $settings
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_payment_gateways_settings
		 */
		return apply_filters( 'woocommerce_pos_payment_gateways_settings', $response );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	public function update_payment_gateways_settings( WP_REST_Request $request ) {
		$settings = array_replace_recursive( $this->get_payment_gateways_settings(), $request->get_params() );
		return $this->save_settings( 'payment_gateways', $settings );
	}

	/**
	 * POST data comes in as PATCH, ie: partial, so we need to merge with existing data.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_general_settings( WP_REST_Request $request ) {
		$settings = array_replace_recursive( $this->get_general_settings(), $request->get_params() );
		return $this->save_settings( 'general', $settings );
	}

	/**
	 * @return array
	 */
	public function get_general_settings(): array {
		$general_settings = array_replace_recursive(
			self::$default_settings['general'],
			get_option( self::$db_prefix . 'general', array() )
		);

		/**
		 * Filters the general settings.
		 *
		 * @param {array} $settings
		 *
		 * @returns {array} $settings
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_general_settings
		 */
		return apply_filters( 'woocommerce_pos_general_settings', $general_settings );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_checkout_settings( WP_REST_Request $request ) {
		$settings = array_replace_recursive( $this->get_checkout_settings(), $request->get_params() );
		return $this->save_settings( 'checkout', $settings );
	}

	/**
	 * @return array
	 */
	public function get_checkout_settings(): array {
		$checkout_settings = array_replace_recursive(
			self::$default_settings['checkout'],
			get_option( self::$db_prefix . 'checkout', array() )
		);

		/**
		 * Filters the checkout settings.
		 *
		 * @param {array} $settings
		 *
		 * @returns {array} $settings
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_checkout_settings
		 */
		return apply_filters( 'woocommerce_pos_checkout_settings', $checkout_settings );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error
	 */
	public function update_access_settings( WP_REST_Request $request ) {
		global $wp_roles;
		$data = $request->get_params();

		// get all role slugs
		$roles = array_keys( $wp_roles->roles );

		// get property from $data where key is in $roles
		$update = array_intersect_key( $data, array_flip( $roles ) );

		// if $role is array with one property, update the capabilities
		if ( 1 === count( $update ) ) {
			$slugs = array_keys( $update );
			$slug = $slugs[0];
			$role = get_role( $slug );

			// flatten capabilities array from 'wc', 'wp', 'wcpos' grouping
			$flattened_caps = array();
			foreach ( $update[ $slug ]['capabilities'] as $capabilities ) {
				$flattened_caps = array_merge( $flattened_caps, $capabilities );
			}

			// update capabilities for each $flattened_cap (should only be one)
			foreach ( $flattened_caps as $cap => $grant ) {
				// sanity check for admin role, read capability
				if ( 'administrator' === $slug && 'read' === $cap ) {
					continue;
				}
				if ( $grant ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}

		return $this->get_access_settings();
	}

	/**
	 *
	 */
	public function get_access_settings(): array {
		global $wp_roles;
		$role_caps = array();

		$roles = $wp_roles->roles;
		if ( $roles ) {
			foreach ( $roles as $slug => $role ) {
				$role_caps[ $slug ] = array(
					'name' => $role['name'],
					'capabilities' => array(
						'wcpos' => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wcpos'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wcpos'] )
						),
						'wc' => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wc'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wc'] )
						),
						'wp' => array_intersect_key(
							array_merge( array_fill_keys( self::$caps['wp'], false ), $role['capabilities'] ),
							array_flip( self::$caps['wp'] )
						),
					),
				);
			}
		}

		/**
		 * Filters the access settings.
		 *
		 * @param {array} $settings
		 *
		 * @returns {array} $settings
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_access_settings
		 */
		return apply_filters( 'woocommerce_pos_access_settings', $role_caps );
	}

	/**
	 * @TODO - who can read settings?
	 *
	 * @return bool
	 */
	public function read_permission_check() {
		//		return current_user_can( 'manage_woocommerce_pos' );
		return true;
	}

	/**
	 * @return bool
	 */
	public function update_permission_check() {
         return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * @return bool
	 */
	public function access_permission_check() {
         return current_user_can( 'promote_users' );
	}
}
