<?php

namespace WCPOS\WooCommercePOS\Services;

use WC_Payment_Gateways;
use WP_Error;
use WP_REST_Request;
use const WCPOS\WooCommercePOS\VERSION;

class Settings {

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
            // this is used in the POS, not in WP Admin (at the moment)
            'dequeue_script_handles' => array(
                'admin-bar',
                'wc-add-to-cart',
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
        'tools' => array(
            'use_jwt_as_param' => false,
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
     * @param string $id
     * @return array|mixed|WP_Error|null
     */
    public function get_settings( string $id ) {
        $method_name = 'get_' . $id . '_settings';

        if ( method_exists( $this, $method_name ) ) {
            return $this->$method_name();
        }

        return new WP_Error(
            'woocommerce_pos_settings_error',
            /* translators: %s: Settings group id, ie: 'general' or 'checkout' */
            sprintf( __( 'Settings with id %s not found', 'woocommerce-pos' ), $id ),
            array( 'status' => 400 )
        );
    }

    /**
     * @param string $id
     * @param array $settings
     * @return array|mixed|WP_Error|null
     */
    public function save_settings( string $id, array $settings ) {
        $success = update_option(
            static::$db_prefix . $id,
            array_merge(
                $settings,
                array( 'date_modified_gmt' => current_time( 'mysql', true ) )
            ),
            false
        );

        if ( $success ) {
            return $this->get_settings( $id );
        }

        return new WP_Error(
            'woocommerce_pos_settings_error',
            /* translators: %s: Settings group id, ie: 'general' or 'checkout' */
            sprintf( __( 'Can not save settings with id %s', 'woocommerce-pos' ), $id ),
            array( 'status' => 400 )
        );
    }

    /**
     * @return array
     */
    public function get_general_settings(): array {
        $default_settings = self::$default_settings['general'];
        $settings = get_option( self::$db_prefix . 'general', array() );

        // if the key does not exist in db settings, use the default settings
        foreach ( $default_settings as $key => $value ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                $settings[ $key ] = $value;
            }
        }

        /**
         * Filters the general settings.
         *
         * @param {array} $settings
         * @returns {array} $settings
         * @since 1.0.0
         * @hook woocommerce_pos_general_settings
         */
        return apply_filters( 'woocommerce_pos_general_settings', $settings );
    }

    /**
     * @return array
     */
    public function get_checkout_settings(): array {
        $default_settings = self::$default_settings['checkout'];
        $settings = get_option( self::$db_prefix . 'checkout', array() );

        // if the key does not exist in db settings, use the default settings
        foreach ( $default_settings as $key => $value ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                $settings[ $key ] = $value;
            }
        }

        /**
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
        $settings = get_option( self::$db_prefix . 'tools', array() );

        // if the key does not exist in db settings, use the default settings
        foreach ( $default_settings as $key => $value ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                $settings[ $key ] = $value;
            }
        }

        /**
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
     *
     */
    public function get_license_settings() {
        /**
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
        $custom_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );

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
         * @returns {array} $settings
         * @since 1.0.0
         * @hook woocommerce_pos_payment_gateways_settings
         */
        return apply_filters( 'woocommerce_pos_payment_gateways_settings', $response );
    }

    /**
     * Delete settings in WP options table
     *
     * @param $id
     * @return bool
     */
    public static function delete_settings( $id ): bool {
        return delete_option( 'woocommerce_pos_' . $id );
    }

    /**
     * Delete all settings in WP options table
     */
    public static function delete_all_settings() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare( "
        DELETE FROM {$wpdb->options}
        WHERE option_name
        LIKE '%s'",
                'woocommerce_pos_%'
            )
        );
    }

    /**
     * @return string
     */
    public static function get_db_version(): string {
        return get_option( 'woocommerce_pos_db_version', '0' );
    }

    /**
     * updates db to new version number
     * bumps the idb version number
     */
    public static function bump_versions() {
        add_option( 'woocommerce_pos_db_version', VERSION, '', 'no' );
    }
}
