<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WP_REST_Request;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Shared clamped-int request-param reader (previously duplicated verbatim in
 * the changes and integrity controllers): absent/blank falls back to the
 * default, everything is clamped into [$min, $max].
 */
trait Request_Int_Param {
	private function int_param( WP_REST_Request $request, string $key, int $default, int $min, int $max ): int {
		$raw   = $request->get_param( $key );
		$value = ( null === $raw || '' === $raw ) ? $default : (int) $raw;

		return max( $min, min( $max, $value ) );
	}
}
