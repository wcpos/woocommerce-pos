<?php
/**
 * Cloudflare detection for WCPOS settings.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Detects Cloudflare proxy and official plugin status.
 */
class Cloudflare_Detector {
	/**
	 * Detect Cloudflare from the current request or injected inputs.
	 *
	 * @param array|null $server         Server values, defaults to $_SERVER.
	 * @param array|null $active_plugins Active plugin basenames, defaults to WordPress detection.
	 * @return array{proxied:bool,plugin_active:bool} Cloudflare status.
	 */
	public function detect( ?array $server = null, ?array $active_plugins = null ): array {
		$server = $server ?? $_SERVER;
		if ( null !== $active_plugins ) {
			$plugin_active = \in_array( 'cloudflare/cloudflare.php', $active_plugins, true );
		} else {
			if ( ! \function_exists( 'is_plugin_active' ) ) {
				$file = ABSPATH . 'wp-admin/includes/plugin.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
			$plugin_active = \function_exists( 'is_plugin_active' ) && is_plugin_active( 'cloudflare/cloudflare.php' );
		}

		return array(
			'proxied'       => ! empty( $server['HTTP_CF_RAY'] ),
			'plugin_active' => $plugin_active,
		);
	}
}
