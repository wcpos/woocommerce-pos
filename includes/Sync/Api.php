<?php
/**
 * Sync REST API registration.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Sync REST API configuration and controller registration.
 */
final class Api {
	public const ROUTE_NAMESPACE = 'wcpos/v1';
	public const ROUTE_PREFIX    = 'sync/';
	public const OPTION_ENABLED  = 'woocommerce_pos_sync_api_enabled';
	public const UUID_META_KEY   = '_woocommerce_pos_uuid';
	public const SCHEMA_VERSION  = '1';
	public const SCHEMA_OPTION   = 'wcpos_sync_schema_version';

	/**
	 * Check whether the sync REST API is enabled.
	 */
	public static function is_enabled(): bool {
		$enabled = (bool) get_option( self::OPTION_ENABLED, false );

		return (bool) apply_filters( 'woocommerce_pos_sync_api_enabled', $enabled );
	}

	/**
	 * Register sync REST API controllers when the feature is enabled.
	 *
	 * @param array $controllers REST API controllers.
	 *
	 * @return array REST API controllers.
	 */
	public static function register_controllers( array $controllers ): array {
		if ( self::is_enabled() ) {
			$controllers['sync-status']     = Status_Controller::class;
			$controllers['sync-catalog']    = Catalog_Proxy_Controller::class;
			$controllers['sync-orders']     = Orders_Controller::class;
			$controllers['sync-changes']    = Changes_Controller::class;
			$controllers['sync-digests']    = Digests_Controller::class;
			$controllers['sync-integrity']  = Integrity_Controller::class;
			$controllers['sync-variations'] = Variations_Controller::class;
			$controllers['sync-resolve']    = Resolve_Controller::class;
		}

		return $controllers;
	}
}
