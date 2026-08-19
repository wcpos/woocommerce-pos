<?php
/**
 * Public site discovery REST API controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Template_Router;
use WP_REST_Response;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Serves the lightweight public site discovery response.
 */
final class Site {
	private const ROUTE = '/wcpos/v2/site';

	/**
	 * Register the site discovery route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'wcpos/v2',
			'/site',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_site' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Classify the discovery route as public.
	 *
	 * @return array<string, string[]>
	 */
	public function wcpos_route_classifications(): array {
		return array( 'public' => array( self::ROUTE ) );
	}

	/**
	 * Return the site discovery response.
	 */
	public function get_site(): WP_REST_Response {
		$data = array(
			'uuid'             => wcpos_get_site_uuid(),
			'name'             => get_bloginfo( 'name' ),
			'description'      => get_bloginfo( 'description' ),
			'url'              => get_option( 'siteurl' ),
			'home'             => home_url(),
			'gmt_offset'       => get_option( 'gmt_offset' ),
			'timezone_string'  => get_option( 'timezone_string' ),
			'site_logo'        => (int) get_theme_mod( 'custom_logo', 0 ),
			'site_icon_url'    => get_site_icon_url(),
			'wp_version'       => get_bloginfo( 'version' ),
			'wcpos_version'    => VERSION,
			'use_jwt_as_param' => SettingsService::instance()->use_jwt_as_param_enabled(),
			'namespaces'       => array( 'wcpos/v2' ),
			'authentication'   => array(
				'wcpos' => array(
					'endpoints' => array(
						'authorization' => Template_Router::get_auth_url(),
					),
				),
			),
		);

		if ( \function_exists( 'WC' ) ) {
			$data['wc_version']   = WC()->version;
			$data['namespaces'][] = 'wc/v3';
		}

		if ( \defined( 'WCPOS\WooCommercePOSPro\VERSION' ) ) {
			$data['wcpos_pro_version'] = \constant( 'WCPOS\WooCommercePOSPro\VERSION' );
		}

		/**
		 * Filters the public site discovery data.
		 *
		 * @param array $data Site discovery data.
		 */
		$data = apply_filters( 'wcpos_rest_site_info', $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public WCPOS site discovery filter.

		return new WP_REST_Response( $data, 200 );
	}
}
