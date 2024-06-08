<?php
/**
 * Cache Helper class
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\Vendor\Phpfastcache\Drivers\Files\Config;
use WCPOS\Vendor\Phpfastcache\Drivers\Files\Driver;
use WCPOS\Vendor\Phpfastcache\EventManager;
use WCPOS\Vendor\Phpfastcache\Helper\Psr16Adapter;

/**
 *
 */
class Cache {
	/**
	 * Get the cache instance
	 *
	 * @param string $instance_id Instance ID.
	 *
	 * @return Psr16Adapter
	 */
	public static function get_cache_instance( string $instance_id = 'default' ) {
		static $cache = null;

		if ( $cache === null ) {
			$upload_dir = wp_upload_dir();
			$cache_dir = $upload_dir['basedir'] . '/woocommerce_pos_cache';

			if ( ! file_exists( $cache_dir ) ) {
				wp_mkdir_p( $cache_dir );
			}

			$config = new Config(
				array(
					'path' => $cache_dir,
				)
			);

			$event_manager = EventManager::getInstance();

			// Generate a unique instance ID for each logged-in user.
			$driver = new Driver( $config, $instance_id );
			$driver->setEventManager( $event_manager );

			$cache = new Psr16Adapter( $driver );
		}

		return $cache;
	}
}
