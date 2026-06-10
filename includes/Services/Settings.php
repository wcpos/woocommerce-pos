<?php
/**
 * Settings.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WP_Error;
use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;
use WCPOS\WooCommercePOS\Services\Settings\Access_Section;
use WCPOS\WooCommercePOS\Services\Settings\Checkout_Section;
use WCPOS\WooCommercePOS\Services\Settings\General_Section;
use WCPOS\WooCommercePOS\Services\Settings\License_Section;
use WCPOS\WooCommercePOS\Services\Settings\Section_Registry;
use WCPOS\WooCommercePOS\Services\Settings\Tax_Ids_Section;
use WCPOS\WooCommercePOS\Services\Settings\Tools_Section;
use WCPOS\WooCommercePOS\Services\Settings\Payment_Gateways_Section;
use WCPOS\WooCommercePOS\Services\Settings\Visibility_Section;
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
	 * The single instance of the class.
	 *
	 * @var null|Settings
	 */
	private static $instance = null;

	/**
	 * The Section Registry. Built lazily on first access so registrants can
	 * hook `woocommerce_pos_register_settings_sections` during plugins_loaded.
	 *
	 * @var null|Section_Registry
	 */
	private $registry = null;

	/**
	 * Get the Section Registry, building and populating it on first access.
	 *
	 * @return Section_Registry
	 */
	public function sections(): Section_Registry {
		if ( null === $this->registry ) {
			// Assign before firing the action: a re-entrant settings read from
			// inside a registration callback gets the partially built registry
			// instead of recursing forever.
			$this->registry = new Section_Registry();

			// Core sections are registered here as they are migrated
			// (Tasks 3-11 append register() calls above the action).
			$this->registry->register( new General_Section() );
			$this->registry->register( new Checkout_Section() );
			$this->registry->register( new Tools_Section() );
			$this->registry->register( new Tax_Ids_Section() );
			$this->registry->register( new Visibility_Section() );
			$this->registry->register( new Payment_Gateways_Section() );
			$this->registry->register( new Access_Section() );
			$this->registry->register( new License_Section() );

			/**
			 * Fires when the Section Registry is built, letting Pro and
			 * extensions register their own Settings Sections.
			 *
			 * Fires lazily on the FIRST settings read of the request. Hook
			 * this action at plugin file load or early plugins_loaded —
			 * callbacks added after the first read never run.
			 *
			 * @since 1.10.0
			 *
			 * @param Section_Registry $registry The Section Registry.
			 *
			 * @hook woocommerce_pos_register_settings_sections
			 */
			do_action( 'woocommerce_pos_register_settings_sections', $this->registry );
		}

		return $this->registry;
	}

	/**
	 * Drop the built registry so tests can exercise registration. Not for
	 * production use.
	 */
	public function reset_sections_for_testing(): void {
		$this->registry = null;
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
		$section = $this->sections()->get( $id );

		if ( $section instanceof Settings_Section_Interface ) {
			$settings = $section->read();

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

		// Legacy path for sections not yet registered.
		$method_name = 'get_' . $id . '_settings';

		if ( method_exists( $this, $method_name ) ) {
			$settings = $this->$method_name();

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
		$section = $this->sections()->get( $id );
		if ( $section instanceof Settings_Section_Interface ) {
			return $section->write( $settings );
		}

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
		$section = $this->sections()->get( 'general' );

		return $section ? $section->read() : array();
	}

	/**
	 * Sanitize the additional free-store tax IDs entered in General settings.
	 *
	 * Delegates to General_Section::sanitize_store_tax_ids(). Kept here as a
	 * static façade because Store_Defaults::tax_ids() calls this method.
	 *
	 * @param mixed $tax_ids Raw tax IDs.
	 * @return array<int,array<string,string>>
	 */
	public static function sanitize_store_tax_ids( $tax_ids ): array {
		return General_Section::sanitize_store_tax_ids( $tax_ids );
	}

	/**
	 * Get tax IDs settings.
	 *
	 * @return array
	 */
	public function get_tax_ids_settings(): array {
		$section = $this->sections()->get( 'tax_ids' );

		return $section ? $section->read() : array();
	}

	/**
	 * Get checkout settings.
	 *
	 * @return array
	 */
	public function get_checkout_settings(): array {
		$section = $this->sections()->get( 'checkout' );

		return $section ? $section->read() : array();
	}

	/**
	 * Get access settings with role capabilities.
	 *
	 * @return array
	 */
	public function get_access_settings(): array {
		$section = $this->sections()->get( 'access' );

		return $section ? $section->read() : array();
	}

	/**
	 * Get tools settings.
	 *
	 * @return array
	 *
	 * @hook woocommerce_pos_tools_settings
	 */
	public function get_tools_settings(): array {
		$section = $this->sections()->get( 'tools' );

		return $section ? $section->read() : array();
	}

	/**
	 * Get license settings.
	 *
	 * @return array
	 */
	public function get_license_settings(): array {
		$section = $this->sections()->get( 'license' );

		return $section ? $section->read() : array();
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
	public function get_payment_gateways_settings(): array {
		$section = $this->sections()->get( 'payment_gateways' );

		return $section ? $section->read() : array();
	}

	/**
	 * POS Visibility settings.
	 *
	 * @return array
	 */
	public function get_visibility_settings(): array {
		$section = $this->sections()->get( 'visibility' );

		return $section ? $section->read() : array();
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
				/* translators: Error message shown when invalid arguments are provided. */
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

		foreach ( array_keys( self::instance()->sections()->all() ) as $id ) {
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
