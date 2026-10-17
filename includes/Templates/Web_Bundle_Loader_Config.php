<?php
/**
 * Configuration for the signed web bundle loader.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates;

class Web_Bundle_Loader_Config {
	const MANIFEST_URL = 'https://cdn.wcpos.com/manifest';
	const BEACON_URL   = 'https://cdn.wcpos.com/beacon';
	// A slow edge must never hold the POS longer than this before jsDelivr takes over.
	const MANIFEST_TIMEOUT_MS     = 5000;
	const SIGNING_PUBLIC_KEYS_JWK = array(
		array(
			'kty' => 'EC',
			'x'   => 'ZCjpS9iwlHsyO2lITGfUR8nrsK9wHhsgBpXtYpk4ELY',
			'y'   => 'C0ehdA6HLh0rv5iiOoSlob_edhj1XtLK-b3OYntG64U',
			'crv' => 'P-256',
		),
	);

	/** Extract the unique RxDB version, scanning only once per worker digest. */
	public static function worker_rxdb_version( string $worker_path, string $worker_hash ): ?string {
		$key    = 'wcpos_worker_rxdb_' . $worker_hash;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached['version'];
		}
		$source = file_get_contents( $worker_path );
		preg_match_all( '/(?:RXDB_VERSION|(?:var |,)[A-Za-z_$][A-Za-z0-9_$]*) *= *["\']([0-9]+\.[0-9]+\.[0-9]+)["\']/', (string) $source, $matches );
		$versions = array_values( array_unique( $matches[1] ) );
		$version  = 1 === \count( $versions ) ? $versions[0] : null;
		set_transient( $key, array( 'version' => $version ) );
		return $version;
	}

	/** Prerelease plugins may include a site identifier in manifest requests. */
	public static function is_prerelease( string $version ): bool {
		return false !== strpos( $version, '-' );
	}

	/** Build the manifest query, preserving its contract order and omitting nulls. */
	public static function manifest_url( string $ref, string $plugin_version, ?string $pro_version, ?string $worker_version, ?string $site_uuid ): string {
		$args = array(
			'ref'    => $ref,
			'plugin' => $plugin_version,
			'worker' => $worker_version,
			'pro'    => $pro_version,
			'site'   => $site_uuid,
		);
		return add_query_arg(
			array_filter(
				$args,
				static function ( $value ) {
					return null !== $value;
				}
			),
			self::MANIFEST_URL
		);
	}

	/** Explicit base URLs and the default local development server bypass the CDN. */
	public static function uses_manifest( string $bundle_ref, bool $development, bool $overridden ): bool {
		return false === strpos( $bundle_ref, '://' ) && ! ( $development && ! $overridden );
	}
}
