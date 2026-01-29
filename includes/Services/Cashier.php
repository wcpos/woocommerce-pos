<?php
/**
 * Cashier.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Abstracts\Store;
use WP_User;

/**
 * Cashier Service class.
 */
class Cashier {
	/**
	 * The single instance of the class.
	 *
	 * @var null|Cashier
	 */
	private static $instance = null;

	/**
	 * Constructor is private to prevent direct instantiation.
	 * Use Cashier::instance() instead.
	 */
	private function __construct() {
	}

	/**
	 * Gets the singleton instance.
	 *
	 * @return Cashier
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get cashier UUID.
	 *
	 * Note: usermeta is shared across all sites in a network, this can cause issues in the POS.
	 * We need to make sure that the cashier uuid is unique per site.
	 *
	 * @param WP_User $user User object.
	 *
	 * @return string UUID for the cashier.
	 */
	public function get_cashier_uuid( WP_User $user ): string {
		$meta_key = '_woocommerce_pos_uuid';

		if ( \function_exists( 'is_multisite' ) && is_multisite() ) {
			$meta_key = $meta_key . '_' . get_current_blog_id();
		}

		$uuid = get_user_meta( $user->ID, $meta_key, true );
		if ( ! $uuid ) {
			$uuid = wp_generate_uuid4();
			update_user_meta( $user->ID, $meta_key, $uuid );
		}

		return $uuid;
	}

	/**
	 * Get cashier data for API responses.
	 *
	 * @param WP_User $user           User object.
	 * @param bool    $include_stores Whether to include stores data.
	 *
	 * @return array Cashier data.
	 */
	public function get_cashier_data( WP_User $user, bool $include_stores = true ): array {
		$uuid        = $this->get_cashier_uuid( $user );
		$last_access = get_user_meta( $user->ID, '_woocommerce_pos_last_access', true );

		$data = array(
			'uuid'         => $uuid,
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'nice_name'    => $user->user_nicename,
			'last_access'  => $last_access ? $last_access : '',
			'avatar_url'   => get_avatar_url( $user->ID ),
		);

		if ( $include_stores ) {
			$stores      = $this->get_accessible_stores( $user );
			$stores_data = array();
			foreach ( $stores as $store ) {
				$stores_data[] = $store->get_data();
			}
			$data['stores'] = $stores_data;
		}

		/*
		 * Filter cashier data.
		 *
		 * @param array   $data Cashier data.
		 * @param WP_User $user User object.
		 * @param bool    $include_stores Whether stores were included.
		 */
		return apply_filters( 'woocommerce_pos_cashier_data', $data, $user, $include_stores );
	}

	/**
	 * Get stores accessible by the cashier.
	 *
	 * @TODO - This currently returns all stores. In the future, this should be
	 * customized based on user meta, roles, or other authorization logic to
	 * return only the stores the cashier is authorized to access.
	 *
	 * @param WP_User $user User object.
	 *
	 * @return array Array of Store objects.
	 */
	public function get_accessible_stores( WP_User $user ): array {
		$stores = wcpos_get_stores();

		/*
		 * Filter stores accessible by cashier.
		 *
		 * @param array   $stores Array of Store objects.
		 * @param WP_User $user   User object.
		 */
		return apply_filters( 'woocommerce_pos_cashier_accessible_stores', $stores, $user );
	}

	/**
	 * Check if a cashier has access to a specific store.
	 *
	 * @param WP_User $user     User object.
	 * @param int     $store_id Store ID.
	 *
	 * @return bool True if cashier has access, false otherwise.
	 */
	public function has_store_access( WP_User $user, int $store_id ): bool {
		$accessible_stores = $this->get_accessible_stores( $user );

		foreach ( $accessible_stores as $store ) {
			if ( $store->get_id() === $store_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a specific store for a cashier if they have access.
	 *
	 * @param WP_User $user     User object.
	 * @param int     $store_id Store ID.
	 *
	 * @return null|Store Store object if accessible, null otherwise.
	 */
	public function get_accessible_store( WP_User $user, int $store_id ): ?Store {
		$accessible_stores = $this->get_accessible_stores( $user );

		foreach ( $accessible_stores as $store ) {
			if ( $store->get_id() === $store_id ) {
				return $store;
			}
		}

		return null;
	}

	/**
	 * Update cashier's last access time.
	 *
	 * @param WP_User $user      User object.
	 * @param string  $timestamp Optional timestamp, defaults to current time.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_last_access( WP_User $user, string $timestamp = '' ): bool {
		if ( empty( $timestamp ) ) {
			$timestamp = current_time( 'mysql' );
		}

		return update_user_meta( $user->ID, '_woocommerce_pos_last_access', $timestamp );
	}

	/**
	 * Check if user has cashier permissions.
	 *
	 * @param WP_User $user User object.
	 *
	 * @return bool True if user has cashier permissions.
	 */
	public function has_cashier_permissions( WP_User $user ): bool {
		return user_can( $user, 'publish_shop_orders' );
	}

	/**
	 * Validate cashier access for API endpoints.
	 *
	 * @param int $current_user_id Current user ID.
	 * @param int $requested_id    Requested cashier ID.
	 *
	 * @return bool True if access is allowed.
	 */
	public function validate_cashier_access( int $current_user_id, int $requested_id ): bool {
		// Users can access their own data.
		if ( $current_user_id === $requested_id ) {
			return true;
		}

		// Administrators can access any cashier data.
		return current_user_can( 'manage_woocommerce' );
	}
}
