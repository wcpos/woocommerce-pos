<?php
/**
 * Settings REST Controller.
 *
 * This class extend `WP_REST_Controller`
 *
 * It's required to follow "Controller Classes" guide before extending this class:
 * <https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/>
 *
 * @class   WCPOS_REST_Controller
 *
 * @see     https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/
 */

namespace WCPOS\WooCommercePOS\API;

use WP_Error;

abstract class Settings_Controller extends Controller {
	/**
	 * Prefix for the $wpdb->options table.
	 * Empty here because I extend this Controller in the Pro plugin
	 *
	 * @var string
	 */
	protected static $db_prefix = '';
	protected static $default_settings = array();

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
	protected function save_settings( string $id, array $settings ) {
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
}
