<?php
/**
 * Sync REST API registration.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\API\V2\Catalog_Proxy_Controller;
use WCPOS\WooCommercePOS\API\V2\Changes_Controller;
use WCPOS\WooCommercePOS\API\V2\Digests_Controller;
use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\API\V2\Orders_Controller;
use WCPOS\WooCommercePOS\API\V2\Resolve_Controller;
use WCPOS\WooCommercePOS\API\V2\Status_Controller;
use WCPOS\WooCommercePOS\API\V2\Uuid_Backfill_Controller;
use WCPOS\WooCommercePOS\API\V2\Variations_Controller;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;

/**
 * Sync REST API configuration and controller registration.
 */
final class Api {
	public const ROUTE_NAMESPACE = 'wcpos/v2';
	public const ADMIN_OP_PATHS  = array( 'uuid/backfill', 'orders/index/backfill', 'integrity/rebuild' );
	public const OPTION_ENABLED  = 'woocommerce_pos_sync_api_enabled';
	public const UUID_META_KEY   = '_woocommerce_pos_uuid';
	// 5: (status, created_at) index on the mutations table for retention pruning.
	public const SCHEMA_VERSION  = '5';
	public const SCHEMA_OPTION   = 'wcpos_sync_schema_version';

	/**
	 * Check whether the sync REST API is enabled.
	 */
	public static function is_enabled(): bool {
		$enabled = (bool) get_option( self::OPTION_ENABLED, false );

		return (bool) apply_filters( 'woocommerce_pos_sync_api_enabled', $enabled );
	}

	/**
	 * Declare routes with special permission-gate handling.
	 *
	 * @return array<string, string[]> Route classifications.
	 */
	public static function route_classifications(): array {
		$admin_routes = array();

		foreach ( self::ADMIN_OP_PATHS as $path ) {
			$admin_routes[] = '/' . self::ROUTE_NAMESPACE . '/' . $path;
		}

		return array(
			'admin_op'       => $admin_routes,
			'rewrite_exempt' => array( '/' . self::ROUTE_NAMESPACE . '/' ),
		);
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
			Response_Telemetry::register_hooks();
			$controllers['sync-status']     = Status_Controller::class;
			$controllers['sync-catalog']    = Catalog_Proxy_Controller::class;
			$controllers['sync-orders']     = Orders_Controller::class;
			$controllers['sync-changes']    = Changes_Controller::class;
			$controllers['sync-digests']    = Digests_Controller::class;
			$controllers['sync-integrity']  = Integrity_Controller::class;
			$controllers['sync-uuid']       = Uuid_Backfill_Controller::class;
			$controllers['sync-variations'] = Variations_Controller::class;
			$controllers['sync-resolve']    = Resolve_Controller::class;
			$controllers['sync-write']      = Write_Controller::class;
		}

		return $controllers;
	}
}
