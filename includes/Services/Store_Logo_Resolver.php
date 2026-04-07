<?php
/**
 * Shared store/site logo resolution.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Store_Logo_Resolver class.
 *
 * Resolves the display logo for a POS store using explicit-store-logo
 * precedence then an optional site-logo fallback.
 */
class Store_Logo_Resolver {
	/**
	 * Resolve the logo URL for the given POS store.
	 *
	 * Precedence:
	 *  1. Explicit store logo (post thumbnail on the store CPT).
	 *  2. WordPress site logo (theme `custom_logo`) – unless the store opts out.
	 *  3. null when nothing matches.
	 *
	 * @param object $pos_store Active POS store object.
	 *
	 * @return string|null
	 */
	public static function resolve( $pos_store ): ?string {
		$store_id      = method_exists( $pos_store, 'get_id' ) ? (int) $pos_store->get_id() : 0;
		$use_site_logo = true;
		if ( $store_id > 0 ) {
			$use_site_logo = 'no' !== get_post_meta( $store_id, '_use_site_logo', true );
		}

		$explicit_logo_url = self::get_explicit_store_logo_url( $store_id );
		if ( null !== $explicit_logo_url ) {
			return $explicit_logo_url;
		}

		if ( ! $use_site_logo ) {
			return null;
		}

		return self::get_site_logo_url();
	}

	/**
	 * Get explicit store logo URL from the store post thumbnail.
	 *
	 * @param int $store_id Active POS store ID.
	 *
	 * @return string|null
	 */
	private static function get_explicit_store_logo_url( int $store_id ): ?string {
		if ( $store_id <= 0 ) {
			return null;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $store_id );
		if ( $thumbnail_id <= 0 ) {
			return null;
		}

		$logo_src = wp_get_attachment_image_src( $thumbnail_id, 'full' );

		return ( is_array( $logo_src ) && ! empty( $logo_src[0] ) ) ? $logo_src[0] : null;
	}

	/**
	 * Get the WordPress site logo URL.
	 *
	 * @return string|null
	 */
	private static function get_site_logo_url(): ?string {
		$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id <= 0 ) {
			return null;
		}

		$site_logo_src = wp_get_attachment_image_src( $custom_logo_id, 'full' );

		return ( is_array( $site_logo_src ) && ! empty( $site_logo_src[0] ) ) ? $site_logo_src[0] : null;
	}
}
