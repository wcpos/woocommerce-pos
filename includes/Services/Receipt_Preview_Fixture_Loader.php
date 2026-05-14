<?php
/**
 * Receipt preview JSON fixture loader.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\PLUGIN_URL;

/**
 * Loads controlled JSON fixture profiles for receipt gallery previews.
 */
class Receipt_Preview_Fixture_Loader {

	/**
	 * Base fixture profile name.
	 *
	 * @var string
	 */
	private const BASE_PROFILE = 'base-receipt';

	/**
	 * Build receipt preview data for a fixture profile.
	 *
	 * @param string|null $profile   Fixture profile name.
	 * @param object|null $pos_store POS store object. Falls back to default store.
	 *
	 * @return array Receipt data.
	 */
	public function build( ?string $profile = null, $pos_store = null ): array {
		$data = ( new Preview_Receipt_Builder() )->build( $pos_store );

		$base_overrides = $this->load_overrides( self::BASE_PROFILE );
		if ( ! empty( $base_overrides ) ) {
			$data = self::deep_merge( $data, $base_overrides );
		}

		$profile = $this->normalize_profile( $profile );
		if ( self::BASE_PROFILE !== $profile ) {
			$profile_overrides = $this->load_overrides( $profile );
			if ( ! empty( $profile_overrides ) ) {
				$data = self::deep_merge( $data, $profile_overrides );
			}
		}

		return $this->resolve_assets( $data );
	}

	/**
	 * Load a fixture profile's overrides.
	 *
	 * @param string $profile Fixture profile name.
	 *
	 * @return array Override data.
	 */
	private function load_overrides( string $profile ): array {
		$file = $this->get_profile_path( $profile );
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );
		if ( ! \is_array( $decoded ) ) {
			return array();
		}

		$overrides = $decoded['overrides'] ?? $decoded;

		return \is_array( $overrides ) ? $overrides : array();
	}

	/**
	 * Resolve profile path.
	 *
	 * @param string $profile Fixture profile name.
	 *
	 * @return string Profile path.
	 */
	private function get_profile_path( string $profile ): string {
		return trailingslashit( PLUGIN_PATH ) . 'templates/gallery/preview-data/' . $profile . '.json';
	}

	/**
	 * Normalize profile names to safe fixture slugs.
	 *
	 * @param string|null $profile Profile name.
	 *
	 * @return string Safe profile name.
	 */
	private function normalize_profile( ?string $profile ): string {
		$profile = \is_string( $profile ) ? sanitize_key( $profile ) : '';

		return '' !== $profile ? $profile : self::BASE_PROFILE;
	}

	/**
	 * Deep merge arrays. Associative arrays merge recursively; lists replace.
	 *
	 * @param array $base     Base array.
	 * @param array $override Override array.
	 *
	 * @return array Merged array.
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if (
				isset( $base[ $key ] )
				&& \is_array( $base[ $key ] )
				&& \is_array( $value )
				&& self::is_assoc( $base[ $key ] )
				&& self::is_assoc( $value )
			) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Determine whether an array is associative.
	 *
	 * @param array $value Array to inspect.
	 *
	 * @return bool True for associative arrays.
	 */
	private static function is_assoc( array $value ): bool {
		if ( array() === $value ) {
			return false;
		}

		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * Resolve asset placeholders in fixture data.
	 *
	 * @param mixed $value Value to resolve.
	 *
	 * @return mixed Resolved value.
	 */
	private function resolve_assets( $value ) {
		if ( \is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->resolve_assets( $item );
			}

			return $value;
		}

		if ( '{{asset:coffee-monster-logo}}' === $value ) {
			return trailingslashit( PLUGIN_URL ) . 'assets/img/template-gallery/preview-assets/coffee-monster.png';
		}

		return $value;
	}
}
