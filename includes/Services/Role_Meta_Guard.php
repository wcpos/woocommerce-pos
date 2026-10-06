<?php
/**
 * Customer role meta protection.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Roles and levels change only through the WP_User role APIs.
 */
final class Role_Meta_Guard {
	/**
	 * Register after other customer meta listeners.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_customer_object_save', array( self::class, 'strip_role_meta' ), PHP_INT_MAX );
	}

	/**
	 * Strip role metadata that WooCommerce copies onto the customer as generic meta.
	 *
	 * @param mixed $customer Object being saved.
	 */
	public static function strip_role_meta( $customer ): void {
		if ( ! $customer instanceof \WC_Customer ) {
			return;
		}

		foreach ( $customer->get_meta_data() as $meta ) {
			if ( self::is_role_meta_key( $meta->key ) ) {
				if ( $meta->id ) {
					$customer->delete_meta_data_by_mid( $meta->id );
				} else {
					$customer->delete_meta_data( $meta->key );
				}
			}
		}
	}

	/**
	 * Identify role and level metadata for any blog prefix.
	 *
	 * @param mixed $key Meta key.
	 * @return bool Whether the key stores roles or a user level.
	 */
	public static function is_role_meta_key( $key ): bool {
		if ( ! \is_string( $key ) ) {
			return false;
		}

		global $wpdb;

		return 1 === preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?(capabilities|user_level)$/i', trim( $key ) );
	}
}
