<?php

namespace WCPOS\WooCommercePOS\Services;

use WC_Payment_Gateways;
use WP_Error;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Settings Service class.
 */
class Settings {
	/**
	 * The single instance of the class.
	 *
	 * @var Settings|null
	 */
	private static $instance = null;

	/**
	 * Prefix for the $wpdb->options table.
	 *
	 * @var string
	 */
	protected static $db_prefix = 'woocommerce_pos_settings_';

	/**
	 * @var array
	 */
	protected static $default_settings = array(
		'general' => array(
			'pos_only_products'           => false,
			'decimal_qty'                 => false,
			'force_ssl'                   => true,
			'default_customer'            => 0,
			'default_customer_is_cashier' => false,
			'barcode_field'               => '_sku',
			'generate_username'           => true,
		),
		'checkout' => array(
			'order_status'    => 'wc-completed',
			'admin_emails'    => true,
			'customer_emails' => true,
			// this is used in the POS, not in WP Admin (at the moment)
			'dequeue_script_handles' => array(
				'admin-bar',
				'wc-add-to-cart',
				'wc-stripe-upe-classic',
			),
			'dequeue_style_handles' => array(
				'admin-bar',
				'woocommerce-general',
				'woocommerce-inline',
				'woocommerce-layout',
				'woocommerce-smallscreen',
				'woocommerce-blocktheme',
				'wp-block-library',
			),
			'disable_wp_head' => false,
			'disable_wp_footer' => false,
		),
		'payment_gateways' => array(
			'default_gateway' => 'pos_cash',
			'gateways'        => array(
				'pos_cash' => array(
					'order'   => 0,
					'enabled' => true,
				),
				'pos_card' => array(
					'order'   => 1,
					'enabled' => true,
				),
			),
		),
		'tools' => array(
			'use_jwt_as_param' => false,
		),
		'visibility' => array(
			'products' => array(
				'default' => array(
					'pos_only' => array(
						'ids' => array(),
					),
					'online_only' => array(
						'ids' => array(),
					),
				),
			),
			'variations' => array(
				'default' => array(
					'pos_only' => array(
						'ids' => array(),
					),
					'online_only' => array(
						'ids' => array(),
					),
				),
			),
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
		'wc' => array(
			'create_users',
			'edit_others_products',
			'edit_others_shop_orders',
			'edit_product',
			'edit_published_products',
			'edit_shop_orders',
			'edit_users',
			'list_users',
			'manage_product_terms',
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
	 * Gets the singleton instance.
	 *
	 * @return Settings
	 */
	public static function instance(): Settings {
		if ( null === self::$instance ) {
				self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor is private to prevent direct instantiation.
	 * Use woocommerce_pos_get_settings() instead.
	 * Or Settings::instance() if you must.
	 */
	private function __construct() {
	}

	/**
	 * @param string     $id
	 * @param null|mixed $key
	 *
	 * @return null|array|mixed|WP_Error
	 */
	public function get_settings( string $id, $key = null ) {
		$method_name = 'get_' . $id . '_settings';

		if ( method_exists( $this, $method_name ) ) {
			$settings = $this->$method_name();

			// If key is not provided, return the entire settings.
			if ( ! \is_string( $key ) ) {
				return $settings;
			}

			if ( ! isset( $settings[ $key ] ) ) {
				return new WP_Error(
					'woocommerce_pos_settings_error',
					// translators: 1. %s: Settings group id, 2. %s: Settings key
					sprintf( __( 'Settings with id %1$s and key %2$s not found', 'woocommerce-pos' ), $id, $key ),
					array( 'status' => 400 )
				);
			}

			return $settings[ $key ];
		}

		return new WP_Error(
			'woocommerce_pos_settings_error',
			// translators: %s: Settings group id, ie: 'general' or 'checkout'
			sprintf( __( 'Settings with id %s not found', 'woocommerce-pos' ), $id ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Saves settings for a specific section.
	 *
	 * @param string $id The ID of the settings section being saved.
	 * @param array  $settings The settings array to be saved.
	 *
	 * @return array|WP_Error Returns the updated settings array on success or WP_Error on failure.
	 */
	public function save_settings( string $id, array $settings ) {
		$settings = array_merge(
			$settings,
			array( 'date_modified_gmt' => current_time( 'mysql', true ) )
		);

		/**
		 * Filters the settings before they are saved.
		 *
		 * Allows modification of the settings array for a specific section before it is saved to the database.
		 *
		 * @since 1.4.12
		 *
		 * @param array  $settings The settings array about to be saved.
		 * @param string $id       The ID of the settings section being saved.
		 */
		$settings = apply_filters( "woocommerce_pos_pre_save_{$id}_settings", $settings, $id );

		$success = update_option( static::$db_prefix . $id, $settings, false );

		if ( $success ) {
			$saved_settings = $this->get_settings( $id );

			/**
			 * Fires after settings for a specific section are successfully saved.
			 *
			 * Provides a way to execute additional logic after a specific settings section is updated.
			 *
			 * @since 1.4.12
			 *
			 * @param array  $saved_settings The settings array that was just saved.
			 * @param string $id             The ID of the settings section that was saved.
			 */
			do_action( "woocommerce_pos_saved_{$id}_settings", $saved_settings, $id );

			return $saved_settings;
		}

		return new WP_Error(
			'woocommerce_pos_settings_error',
			// translators: %s: Settings group id, ie: 'general' or 'checkout'
			sprintf( __( 'Can not save settings with id %s', 'woocommerce-pos' ), $id ),
			array( 'status' => 400 )
		);
	}

	/**
	 * @return array
	 */
	public function get_general_settings(): array {
		$default_settings = self::$default_settings['general'];
		$settings         = get_option( self::$db_prefix . 'general', array() );

		// if the key does not exist in db settings, use the default settings
		foreach ( $default_settings as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		/*
		 * Filters the general settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings
		 *
		 * @return array $settings
		 *
		 * @hook woocommerce_pos_general_settings
		 */
		return apply_filters( 'woocommerce_pos_general_settings', $settings );
	}

	/**
	 * @return array
	 */
	public function get_checkout_settings(): array {
		$default_settings = self::$default_settings['checkout'];
		$settings         = get_option( self::$db_prefix . 'checkout', array() );

		// if the key does not exist in db settings, use the default settings
		foreach ( $default_settings as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		/*
		 * Filters the checkout settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_checkout_settings
		 */
		return apply_filters( 'woocommerce_pos_checkout_settings', $settings );
	}


	public function get_access_settings(): array {
		global $wp_roles;
		$role_caps = array();

		$roles = $wp_roles->roles;
		if ( $roles ) {
			foreach ( $roles as $slug => $role ) {
				$role_caps[ $slug ] = array(
					'name'         => $role['name'],
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

		/*
		 * Filters the access settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_access_settings
		 */
		return apply_filters( 'woocommerce_pos_access_settings', $role_caps );
	}

	/**
	 * @return array
	 */
	public function get_tools_settings(): array {
		$default_settings = self::$default_settings['tools'];
		$settings         = get_option( self::$db_prefix . 'tools', array() );

		// if the key does not exist in db settings, use the default settings
		foreach ( $default_settings as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		/*
		 * Filters the tools settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.3.6
		 * @hook woocommerce_pos_general_settings
		 */
		return apply_filters( 'woocommerce_pos_tools_settings', $settings );
	}


	public function get_license_settings() {
		/*
		 * Filters the license settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_license_settings
		 */
		return apply_filters( 'woocommerce_pos_license_settings', array() );
	}

	/**
	 * @return array
	 */
	public function get_barcodes(): array {
		global $wpdb;

		// maybe add custom barcode field
		$custom_field = $this->get_settings( 'general', 'barcode_field' );

		// Prepare the basic query
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

		if ( ! empty( $custom_field ) ) {
			$result[] = $custom_field;
		}

		sort( $result );

		return array_unique( $result );
	}

	/**
	 * @return array
	 */
	public function get_order_statuses(): array {
		$order_statuses = wc_get_order_statuses();

		return array_map( 'wc_get_order_status_name', $order_statuses );
	}


	public function get_payment_gateways_settings() {
		// Note: I need to re-init the gateways here to pass the tests, but it seems to work fine in the app.
		WC_Payment_Gateways::instance()->init();
		$installed_gateways = WC_Payment_Gateways::instance()->payment_gateways();
		$gateways_settings  = array_replace_recursive(
			self::$default_settings['payment_gateways'],
			get_option( self::$db_prefix . 'payment_gateways', array() )
		);

		// NOTE - gateways can be installed and uninstalled, so we need to assume the settings data is stale
		$response = array(
			'default_gateway' => $gateways_settings['default_gateway'],
			'gateways'        => array(),
		);

		// loop through installed gateways and merge with saved settings
		foreach ( $installed_gateways as $id => $gateway ) {
			// sanity check for gateway class
			if ( ! is_a( $gateway, 'WC_Payment_Gateway' ) || 'pre_install_woocommerce_payments_promotion' === $id ) {
				continue;
			}
			$response['gateways'][ $id ] = array_replace_recursive(
				array(
					'id'          => $gateway->id,
					'title'       => $gateway->title,
					'description' => $gateway->description,
					'enabled'     => false,
					'order'       => 999,
				),
				$gateways_settings['gateways'][ $id ] ?? array()
			);
		}

		/*
		 * Filters the payment gateways settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_payment_gateways_settings
		 */
		return apply_filters( 'woocommerce_pos_payment_gateways_settings', $response );
	}

	/**
	 * POS Visibility settings.
	 */
	public function get_visibility_settings() {
		$default_settings = self::$default_settings['visibility'];
		$settings         = get_option( self::$db_prefix . 'visibility', array() );

		// if the key does not exist in db settings, use the default settings
		foreach ( $default_settings as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		/*
		 * Filters the visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_visibility_settings', $settings );
	}

	/**
	 * Update visibility settings.
	 *
	 * @param array $args The visibility settings to update.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_visibility_settings( array $args ) {
			// Validate and normalize arguments.
		if ( empty( $args['post_type'] ) || ! isset( $args['ids'] ) ) {
			return new WP_Error(
				'woocommerce_pos_settings_error',
				__( 'Invalid arguments provided', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		// Define valid visibility options.
		$valid_options = array( 'pos_only', 'online_only', '' );

		// Check if visibility is set and valid.
		if ( ! isset( $args['visibility'] ) || ! in_array( $args['visibility'], $valid_options, true ) ) {
			return new WP_Error(
				'woocommerce_pos_settings_error',
				__( 'Invalid visibility option provided', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$post_type = $args['post_type'];
		$scope = $args['scope'] ?? 'default';
		$visibility = $args['visibility'] ?? '';
		$ids = is_array( $args['ids'] ) ? $args['ids'] : array( $args['ids'] );
		$ids = array_filter( array_map( 'intval', $ids ) ); // Force to array of integers.

		// Get the current visibility settings.
		$current_settings = $this->get_visibility_settings();

		// Define the opposite visibility type.
		$opposite_visibility = ( $visibility === 'pos_only' ) ? 'online_only' : 'pos_only';

		// Add or remove IDs based on the visibility type.
		foreach ( $ids as $id ) {
			if ( $visibility === '' ) {
				// Remove from both pos_only and online_only.
				$current_settings[ $post_type ][ $scope ]['pos_only']['ids'] = $this->remove_id_from_visibility(
					$current_settings[ $post_type ][ $scope ]['pos_only']['ids'],
					$id
				);
				$current_settings[ $post_type ][ $scope ]['online_only']['ids'] = $this->remove_id_from_visibility(
					$current_settings[ $post_type ][ $scope ]['online_only']['ids'],
					$id
				);
			} else {
				// Add to the specified visibility type.
				$current_settings[ $post_type ][ $scope ][ $visibility ]['ids'] = $this->add_id_to_visibility(
					$current_settings[ $post_type ][ $scope ][ $visibility ]['ids'],
					$id
				);
				// Remove from the opposite visibility type.
				$current_settings[ $post_type ][ $scope ][ $opposite_visibility ]['ids'] = $this->remove_id_from_visibility(
					$current_settings[ $post_type ][ $scope ][ $opposite_visibility ]['ids'],
					$id
				);
			}
		}

		return $this->save_settings( 'visibility', $current_settings );
	}

	/**
	 * Add an ID to a visibility type if it doesn't already exist.
	 *
	 * @param array $ids The current array of IDs.
	 * @param int   $id The ID to add.
	 * @return array The updated array of IDs.
	 */
	private function add_id_to_visibility( array $ids, int $id ): array {
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Remove an ID from a visibility type if it exists.
	 *
	 * @param array $ids The current array of IDs.
	 * @param int   $id The ID to remove.
	 * @return array The updated array of IDs.
	 */
	private function remove_id_from_visibility( array $ids, int $id ): array {
		return array_filter(
			$ids,
			function ( $existing_id ) use ( $id ) {
				return $existing_id !== $id;
			}
		);
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope  The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { pos_only: { ids: [1, 2, 3] }, online_only: { ids: [4, 5, 6] }
	 */
	public function get_product_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_visibility_settings();

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_product_visibility_settings', $settings['products'][ $scope ], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope  The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
	 */
	public function get_pos_only_product_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_product_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_pos_only_product_visibility_settings', $settings['pos_only'], $scope );
	}

		/**
		 * Get product visibility settings.
		 *
		 * @param string $scope  The scope of the settings to get. 'default' or store ID.
		 *
		 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
		 */
	public function get_online_only_product_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_product_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_online_only_product_visibility_settings', $settings['online_only'], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope  The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { pos_only: { ids: [1, 2, 3] }, online_only: { ids: [4, 5, 6] }
	 */
	public function get_variations_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_visibility_settings();

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_variations_visibility_settings', $settings['variations'][ $scope ], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope  The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
	 */
	public function get_pos_only_variations_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_variations_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_pos_only_variations_visibility_settings', $settings['pos_only'], $scope );
	}

		/**
		 * Get product visibility settings.
		 *
		 * @param string $scope  The scope of the settings to get. 'default' or store ID.
		 *
		 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
		 */
	public function get_online_only_variations_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_variations_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_online_only_variations_visibility_settings', $settings['online_only'], $scope );
	}

	/**
	 * Check if a product is POS only.
	 *
	 * @param string|int $product_id
	 *
	 * @return bool
	 */
	public function is_product_pos_only( $product_id ) {
		$product_id = (int) $product_id;
		$settings = $this->get_pos_only_product_visibility_settings();
		$pos_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return in_array( $product_id, $pos_only_ids, true );
	}

	/**
	 * Check if a product is Online only.
	 *
	 * @param string|int $product_id
	 *
	 * @return bool
	 */
	public function is_product_online_only( $product_id ) {
		$product_id = (int) $product_id;
		$settings = $this->get_online_only_product_visibility_settings();
		$online_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return in_array( $product_id, $online_only_ids, true );
	}

	/**
	 * Check if a variation is POS only.
	 *
	 * @param string|int $variation_id
	 *
	 * @return bool
	 */
	public function is_variation_pos_only( $variation_id ) {
		$variation_id = (int) $variation_id;
		$settings = $this->get_pos_only_variations_visibility_settings();
		$pos_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return in_array( $variation_id, $pos_only_ids, true );
	}

		/**
		 * Check if a variation is Online only.
		 *
		 * @param string|int $variation_id
		 *
		 * @return bool
		 */
	public function is_variation_online_only( $variation_id ) {
		$variation_id = (int) $variation_id;
		$settings = $this->get_online_only_variations_visibility_settings();
		$online_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return in_array( $variation_id, $online_only_ids, true );
	}


	/**
	 * Delete settings in WP options table.
	 *
	 * @param $id
	 *
	 * @return bool|WP_Error
	 */
	public static function delete_settings( $id ) {
		if ( ! is_super_admin() && ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error( 'unauthorized', 'You do not have permission to delete this option.' );
		}

		return delete_option( self::$db_prefix . $id );
	}

	/**
	 * Delete all settings in WP options table.
	 *
	 * @return bool|WP_Error
	 */
	public static function delete_all_settings() {
		if ( ! is_super_admin() && ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error( 'unauthorized', 'You do not have permission to delete this option.' );
		}

		foreach ( self::$default_settings as $id => $settings ) {
			delete_option( self::$db_prefix . $id );
		}

		return true;
	}

	/**
	 * @return string
	 */
	public static function get_db_version() {
		return get_option( 'woocommerce_pos_db_version', '0' );
	}

	/**
	 * updates db to new version number
	 * bumps the idb version number.
	 */
	public static function bump_versions(): void {
		update_option( 'woocommerce_pos_db_version', VERSION );
	}
}
