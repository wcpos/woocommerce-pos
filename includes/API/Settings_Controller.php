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

class Settings_Controller extends Controller {
	protected static $db_prefix = '';
	protected static $default_settings = array();

	/**
	 * @param string $key
	 * @return array|mixed|WP_Error|null
	 */
	public function get_settings( string $key ) {
		$method_name = 'get_' . $key . '_settings';
		if ( method_exists( $this, $method_name ) ) {
			return $this->$method_name();
		} else {
			return new WP_Error( 'cant-get', __( 'message', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param string $key
	 * @param array $settings
	 * @return array|mixed|WP_Error|null
	 */
	protected function save_settings( string $key, array $settings ) {
		$success = update_option(
			self::$db_prefix . $key,
			array_merge(
				array( 'date_modified_gmt' => current_time( 'mysql', true ) ),
				$settings
			),
			false
		);

		if ( $success ) {
			return $this->get_settings( $key );
		}

		return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}

	/**
	 * Merges the given array settings with the defaults.
	 *
	 * @param string $group
	 * @param array $settings
	 *
	 * @return array
	 */
	public function merge_settings( array $settings, array $default ): array {
		return wp_parse_args( array_intersect_key( $settings, $default ), $default );
	}
}
