<?php
/**
 * Settings.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Payment_Gateways;
use WP_Error;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Settings Service class.
 */
class Settings {
	/**
	 * Prefix for the $wpdb->options table.
	 *
	 * @var string
	 */
	protected static $db_prefix = 'woocommerce_pos_settings_';

	/**
	 * Default settings for all sections.
	 *
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
			'restore_stock_on_delete'     => true,
			'tracking_consent'            => 'undecided',
			'store_name'                  => '',
			'store_phone'                 => '',
			'store_email'                 => '',
			'policies_and_conditions'     => '',
			'store_tax_ids'               => array(),
		),
		'tax_ids' => array(
			// Per-type meta-key write overrides. Empty by default: the composed
			// write_map (defaults + plugin detection + scan) is used.
			'write_map' => array(),
		),
		'checkout' => array(
			'receipt_default_mode' => 'fiscal',
			'admin_emails'    => array(
				'enabled'         => true,
				'new_order'       => true,
				'cancelled_order' => true,
				'failed_order'    => true,
			),
			'customer_emails' => array(
				'enabled'                   => true,
				'customer_on_hold_order'    => true,
				'customer_processing_order' => true,
				'customer_completed_order'  => true,
				'customer_refunded_order'   => true,
				'customer_failed_order'     => true,
			),
			'cashier_emails'  => array(
				'enabled'   => false,
				'new_order' => true,
			),
			// this is used in the POS, not in WP Admin (at the moment).
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
			'disable_wp_head'   => false,
			'disable_wp_footer' => false,
		),
		'payment_gateways' => array(
			'default_gateway' => 'pos_cash',
			'gateways'        => array(
				'pos_cash' => array(
					'order'        => 0,
					'enabled'      => true,
					'order_status' => 'wc-completed',
				),
				'pos_card' => array(
					'order'        => 1,
					'enabled'      => true,
					'order_status' => 'wc-completed',
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
	 * The single instance of the class.
	 *
	 * @var null|Settings
	 */
	private static $instance = null;

	/**
	 * Get capabilities grouped by type.
	 *
	 * WooCommerce 9.9 replaced promote_users with create_customers for
	 * customer creation via the REST API. We show the correct capability
	 * on the Access settings page based on the installed WC version.
	 *
	 * @return array
	 */
	private static function get_caps(): array {
		$customer_create_cap = version_compare( WC()->version, '9.9', '>=' )
			? 'create_customers'
			: 'promote_users';

		return array(
			'wcpos' => array(
				'access_woocommerce_pos',
				'manage_woocommerce_pos',
			),
			'wc' => array(
				$customer_create_cap,
				'read_private_products',
				'edit_product',
				'edit_others_products',
				'edit_published_products',
				'read_private_shop_orders',
				'publish_shop_orders',
				'edit_shop_orders',
				'edit_others_shop_orders',
				'edit_users',
				'list_users',
				'manage_product_terms',
				'read_private_shop_coupons',
			),
			'wp' => array(
				'read',
			),
		);
	}

	/**
	 * Constructor is private to prevent direct instantiation.
	 * Use woocommerce_pos_get_settings() instead.
	 * Or Settings::instance() if you must.
	 */
	private function __construct() {
	}

	/**
	 * Gets the singleton instance.
	 *
	 * @return Settings
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get settings for a specific section.
	 *
	 * @param string     $id  The settings section ID.
	 * @param null|mixed $key The specific setting key.
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
					// translators: 1. %s: Settings group id, 2. %s: Settings key.
					\sprintf( __( 'Settings with id %1$s and key %2$s not found', 'woocommerce-pos' ), $id, $key ),
					array( 'status' => 400 )
				);
			}

			return $settings[ $key ];
		}

		return new WP_Error(
			'woocommerce_pos_settings_error',
			// translators: %s: Settings group id, ie: 'general' or 'checkout'.
			\sprintf( __( 'Settings with id %s not found', 'woocommerce-pos' ), $id ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Saves settings for a specific section.
	 *
	 * @param string $id       The ID of the settings section being saved.
	 * @param array  $settings The settings array to be saved.
	 *
	 * @return array|WP_Error Returns the updated settings array on success or WP_Error on failure.
	 */
	public function save_settings( string $id, array $settings ) {
		$sanitize_method = 'sanitize_' . $id . '_settings';
		if ( method_exists( $this, $sanitize_method ) ) {
			$settings = $this->$sanitize_method( $settings );
		}

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

		$option_name    = static::$db_prefix . $id;
		$previous_value = get_option( $option_name, null );
		$success        = update_option( $option_name, $settings, false );

		if ( ! $success ) {
			// update_option() returns false both when the value is unchanged (no DB write) and on
			// actual failure. Use the value read *before* the write attempt to avoid a post-write
			// race: a concurrent request could change the option between our write and a re-read.
			$is_noop = null !== $previous_value
				&& maybe_serialize( $previous_value ) === maybe_serialize( $settings );

			if ( ! $is_noop ) {
				return new WP_Error(
					'woocommerce_pos_settings_error',
					// translators: %s: Settings group id, ie: 'general' or 'checkout'.
					\sprintf( __( 'Can not save settings with id %s', 'woocommerce-pos' ), $id ),
					array( 'status' => 400 )
				);
			}
		}

		$saved_settings = $this->get_settings( $id );

		if ( $success ) {
			/*
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
		}

		return $saved_settings;
	}

	/**
	 * Get general settings.
	 *
	 * @return array
	 */
	public function get_general_settings(): array {
		$default_settings = self::$default_settings['general'];
		$settings         = get_option( self::$db_prefix . 'general', array() );

		// Migrate tracking_consent from the legacy `tools` option if it was set there
		// before being moved to `general`. Only applies when the general option has no
		// value yet, so an explicit general-level choice always wins.
		if ( ! \array_key_exists( 'tracking_consent', $settings ) ) {
			$legacy_tools = get_option( self::$db_prefix . 'tools', array() );
			if ( \is_array( $legacy_tools ) && \array_key_exists( 'tracking_consent', $legacy_tools ) ) {
				$settings['tracking_consent'] = $legacy_tools['tracking_consent'];
			}
		}

		// if the key does not exist in db settings, use the default settings.
		foreach ( $default_settings as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}
		$settings['store_tax_ids'] = self::sanitize_store_tax_ids( $settings['store_tax_ids'] );

		// Expose resolved fallbacks so the React UI can render them as
		// placeholders for store_name / store_phone / store_email /
		// policies_and_conditions when the user has not entered a value.
		$settings['store_defaults'] = Store_Defaults::fallbacks();

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
	 * Sanitize general settings before persisting.
	 *
	 * @param array $settings General settings.
	 * @return array
	 */
	protected function sanitize_general_settings( array $settings ): array {
		if ( \array_key_exists( 'store_tax_ids', $settings ) ) {
			$settings['store_tax_ids'] = self::sanitize_store_tax_ids( $settings['store_tax_ids'] );
		}

		foreach ( array( 'store_name', 'store_phone' ) as $key ) {
			if ( \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = \is_string( $settings[ $key ] )
					? sanitize_text_field( $settings[ $key ] )
					: '';
			}
		}

		if ( \array_key_exists( 'store_email', $settings ) ) {
			$email                  = \is_string( $settings['store_email'] ) ? trim( $settings['store_email'] ) : '';
			$settings['store_email'] = ( '' !== $email && is_email( $email ) ) ? sanitize_email( $email ) : '';
		}

		if ( \array_key_exists( 'policies_and_conditions', $settings ) ) {
			$settings['policies_and_conditions'] = \is_string( $settings['policies_and_conditions'] )
				? sanitize_textarea_field( $settings['policies_and_conditions'] )
				: '';
		}

		// store_defaults is a read-only computed field for the UI; never persist it.
		unset( $settings['store_defaults'] );

		return $settings;
	}

	/**
	 * Sanitize the additional free-store tax IDs entered in General settings.
	 *
	 * Drops malformed rows and keeps optional country/label fields only when
	 * non-empty. Values are preserved verbatim apart from normal text-field
	 * sanitization and surrounding whitespace.
	 *
	 * @param mixed $tax_ids Raw tax IDs.
	 * @return array<int,array<string,string>>
	 */
	public static function sanitize_store_tax_ids( $tax_ids ): array {
		if ( ! \is_array( $tax_ids ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $tax_ids as $tax_id ) {
			if ( ! \is_array( $tax_id ) ) {
				continue;
			}

			$type  = isset( $tax_id['type'] ) && \is_string( $tax_id['type'] )
				? sanitize_key( $tax_id['type'] )
				: '';
			$value = isset( $tax_id['value'] ) && \is_string( $tax_id['value'] )
				? trim( sanitize_text_field( $tax_id['value'] ) )
				: '';

			if ( '' === $type || '' === $value ) {
				continue;
			}

			$entry = array(
				'type'  => $type,
				'value' => $value,
			);

			$country = isset( $tax_id['country'] ) && \is_string( $tax_id['country'] )
				? strtoupper( trim( sanitize_text_field( $tax_id['country'] ) ) )
				: '';
			if ( '' !== $country ) {
				$entry['country'] = $country;
			}

			$label = isset( $tax_id['label'] ) && \is_string( $tax_id['label'] )
				? trim( sanitize_text_field( $tax_id['label'] ) )
				: '';
			if ( '' !== $label ) {
				$entry['label'] = $label;
			}

			$sanitized[] = $entry;
		}

		return $sanitized;
	}

	/**
	 * Get tax IDs settings.
	 *
	 * Defaults are merged for any missing keys so the SPA always receives the
	 * full subtree shape.
	 *
	 * @return array
	 */
	public function get_tax_ids_settings(): array {
		$default_settings = self::$default_settings['tax_ids'];
		$settings         = get_option( self::$db_prefix . 'tax_ids', array() );

		if ( ! \is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! \array_key_exists( 'write_map', $settings ) ) {
			$legacy_general = get_option( self::$db_prefix . 'general', array() );
			$legacy_tax_ids = array();

			if (
				\is_array( $legacy_general )
				&& isset( $legacy_general['tax_ids'] )
				&& \is_array( $legacy_general['tax_ids'] )
			) {
				$legacy_tax_ids = $legacy_general['tax_ids'];
			}

			if ( isset( $legacy_tax_ids['write_map'] ) && \is_array( $legacy_tax_ids['write_map'] ) ) {
				$settings['write_map'] = $legacy_tax_ids['write_map'];
			}
		}

		foreach ( $default_settings as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		/*
		 * Filters the tax IDs settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @hook woocommerce_pos_tax_ids_settings
		 */
		return apply_filters( 'woocommerce_pos_tax_ids_settings', $settings );
	}

	/**
	 * Get checkout settings.
	 *
	 * @return array
	 */
	public function get_checkout_settings(): array {
		$default_settings = self::$default_settings['checkout'];
		$settings         = get_option( self::$db_prefix . 'checkout', array() );

		// if the key does not exist in db settings, use the default settings.
		foreach ( $default_settings as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		// Migrate legacy boolean email settings to array format.
		foreach ( array( 'admin_emails', 'customer_emails' ) as $key ) {
			if ( isset( $settings[ $key ] ) && \is_bool( $settings[ $key ] ) ) {
				$defaults            = $default_settings[ $key ];
				$defaults['enabled'] = $settings[ $key ];
				$settings[ $key ]    = $defaults;
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

	/**
	 * Get access settings with role capabilities.
	 *
	 * @return array
	 */
	public function get_access_settings(): array {
		global $wp_roles;
		$role_caps = array();
		$caps      = self::get_caps();

		$roles = $wp_roles->roles;
		if ( $roles ) {
			foreach ( $roles as $slug => $role ) {
				$role_caps[ $slug ] = array(
					'name'         => $role['name'],
					'capabilities' => array(
						'wcpos' => array_intersect_key(
							array_merge( array_fill_keys( $caps['wcpos'], false ), $role['capabilities'] ),
							array_flip( $caps['wcpos'] )
						),
						'wc' => array_intersect_key(
							array_merge( array_fill_keys( $caps['wc'], false ), $role['capabilities'] ),
							array_flip( $caps['wc'] )
						),
						'wp' => array_intersect_key(
							array_merge( array_fill_keys( $caps['wp'], false ), $role['capabilities'] ),
							array_flip( $caps['wp'] )
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
	 * Get tools settings.
	 *
	 * @return array
	 */
	public function get_tools_settings(): array {
		$default_settings = self::$default_settings['tools'];
		$settings         = get_option( self::$db_prefix . 'tools', array() );

		// if the key does not exist in db settings, use the default settings.
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

	/**
	 * Get license settings.
	 *
	 * @return array
	 */
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
	 * Get available barcode fields.
	 *
	 * @return array
	 */
	public function get_barcodes(): array {
		global $wpdb;

		// maybe add custom barcode field.
		$custom_field = $this->get_settings( 'general', 'barcode_field' );

		// Prepare the basic query.
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
	 * Get available order statuses.
	 *
	 * @return array
	 */
	public function get_order_statuses(): array {
		$order_statuses = wc_get_order_statuses();

		return array_map( 'wc_get_order_status_name', $order_statuses );
	}

	/**
	 * Get payment gateways settings.
	 *
	 * @return array
	 */
	public function get_payment_gateways_settings() {
		// Note: I need to re-init the gateways here to pass the tests, but it seems to work fine in the app.
		WC_Payment_Gateways::instance()->init();
		$installed_gateways = WC_Payment_Gateways::instance()->payment_gateways();
		$raw_gw_option     = get_option( self::$db_prefix . 'payment_gateways', array() );
		$gateways_settings = array_replace_recursive(
			self::$default_settings['payment_gateways'],
			$raw_gw_option
		);

		// Migrate: if old global checkout order_status exists, apply to all gateways.
		$checkout_settings = get_option( self::$db_prefix . 'checkout', array() );
		if ( isset( $checkout_settings['order_status'] ) ) {
			$global_status = $checkout_settings['order_status'];
			if ( \is_string( $global_status ) && '' !== $global_status ) {
				foreach ( $gateways_settings['gateways'] as $gw_id => &$gw_data ) {
					// Check the raw DB value, not the merged value (which includes defaults).
					if ( ! isset( $raw_gw_option['gateways'][ $gw_id ]['order_status'] ) ) {
						$gw_data['order_status'] = $global_status;
					}
				}
				unset( $gw_data );
			}
			// Remove the old global setting.
			unset( $checkout_settings['order_status'] );
			update_option( self::$db_prefix . 'checkout', $checkout_settings );
			update_option( self::$db_prefix . 'payment_gateways', $gateways_settings );
		}

		// NOTE - gateways can be installed and uninstalled, so we need to assume the settings data is stale.
		$response = array(
			'default_gateway' => $gateways_settings['default_gateway'],
			'gateways'        => array(),
		);

		// Gateways that represent deferred/unverified payment default to on-hold.
		$on_hold_gateways = array( 'bacs', 'cheque' );

		// loop through installed gateways and merge with saved settings.
		foreach ( $installed_gateways as $id => $gateway ) {
			// sanity check for gateway class.
			if ( ! is_a( $gateway, 'WC_Payment_Gateway' ) || 'pre_install_woocommerce_payments_promotion' === $id ) {
				continue;
			}

			$default_status = in_array( $id, $on_hold_gateways, true ) ? 'wc-on-hold' : 'wc-completed';

			$response['gateways'][ $id ] = array_replace_recursive(
				array(
					'id'           => $gateway->id,
					'title'        => $gateway->title,
					'description'  => $gateway->description,
					'enabled'      => false,
					'order'        => 999,
					'order_status' => $default_status,
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

		// if the key does not exist in db settings, use the default settings.
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
	 *
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
		if ( ! isset( $args['visibility'] ) || ! \in_array( $args['visibility'], $valid_options, true ) ) {
			return new WP_Error(
				'woocommerce_pos_settings_error',
				__( 'Invalid visibility option provided', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$post_type  = $args['post_type'];
		$scope      = $args['scope'] ?? 'default';
		$visibility = $args['visibility'];
		$ids        = \is_array( $args['ids'] ) ? $args['ids'] : array( $args['ids'] );
		$ids        = array_filter( array_map( 'intval', $ids ) ); // Force to array of integers.

		// Get the current visibility settings.
		$current_settings = $this->get_visibility_settings();

		// Define the opposite visibility type.
		$opposite_visibility = ( 'pos_only' === $visibility ) ? 'online_only' : 'pos_only';

		// Add or remove IDs based on the visibility type.
		foreach ( $ids as $id ) {
			if ( '' === $visibility ) {
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
	 * Get product visibility settings.
	 *
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
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
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
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
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
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
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
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
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
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
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
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
	 * @param int|string $product_id The product ID.
	 *
	 * @return bool
	 */
	public function is_product_pos_only( $product_id ) {
		$product_id   = (int) $product_id;
		$settings     = $this->get_pos_only_product_visibility_settings();
		$pos_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $product_id, $pos_only_ids, true );
	}

	/**
	 * Check if a product is Online only.
	 *
	 * @param int|string $product_id The product ID.
	 *
	 * @return bool
	 */
	public function is_product_online_only( $product_id ) {
		$product_id      = (int) $product_id;
		$settings        = $this->get_online_only_product_visibility_settings();
		$online_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $product_id, $online_only_ids, true );
	}

	/**
	 * Check if a variation is POS only.
	 *
	 * @param int|string $variation_id The variation ID.
	 *
	 * @return bool
	 */
	public function is_variation_pos_only( $variation_id ) {
		$variation_id = (int) $variation_id;
		$settings     = $this->get_pos_only_variations_visibility_settings();
		$pos_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $variation_id, $pos_only_ids, true );
	}

	/**
	 * Check if a variation is Online only.
	 *
	 * @param int|string $variation_id The variation ID.
	 *
	 * @return bool
	 */
	public function is_variation_online_only( $variation_id ) {
		$variation_id    = (int) $variation_id;
		$settings        = $this->get_online_only_variations_visibility_settings();
		$online_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $variation_id, $online_only_ids, true );
	}


	/**
	 * Delete settings in WP options table.
	 *
	 * @param string $id The settings section ID.
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
	 * Get the database version.
	 *
	 * @return string
	 */
	public static function get_db_version() {
		return get_option( 'woocommerce_pos_db_version', '0' );
	}

	/**
	 * Updates db to new version number
	 * bumps the idb version number.
	 */
	public static function bump_versions(): void {
		update_option( 'woocommerce_pos_db_version', VERSION );
	}

	/**
	 * Add an ID to a visibility type if it doesn't already exist.
	 *
	 * @param array $ids The current array of IDs.
	 * @param int   $id  The ID to add.
	 *
	 * @return array The updated array of IDs.
	 */
	private function add_id_to_visibility( array $ids, int $id ): array {
		if ( ! \in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * Remove an ID from a visibility type if it exists.
	 *
	 * @param array $ids The current array of IDs.
	 * @param int   $id  The ID to remove.
	 *
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
}
