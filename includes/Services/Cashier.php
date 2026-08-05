<?php
/**
 * Cashier.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Abstracts\Store;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
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
	 * Delegates to Pos_Uuid — the sole authority for `_woocommerce_pos_uuid` — so
	 * the /cashier endpoint and auth payloads serve the SAME identity as the
	 * /customers endpoint. The POS client keys its RxDB documents on this uuid, so
	 * a divergent value makes one person appear as two.
	 *
	 * Historical note: this method used to mint its own uuid under a per-blog meta
	 * key (`_woocommerce_pos_uuid_{blog_id}`) on multisite while every other reader
	 * used the plain network-wide key. That per-site rule was never applied to
	 * customers, so it forked identities instead of isolating them. Existing
	 * per-blog values are adopted as the network identity when no plain uuid exists
	 * yet; the legacy rows are left in place.
	 *
	 * @param WP_User $user User object.
	 *
	 * @return string UUID for the cashier.
	 */
	public function get_cashier_uuid( WP_User $user ): string {
		$this->maybe_adopt_legacy_multisite_uuid( $user );

		$uuid = Pos_Uuid::ensure_user_uuid( $user );
		if ( '' !== $uuid ) {
			return $uuid;
		}

		// Fallback when the WC_Customer adapter is unavailable: read or mint the
		// plain network-wide key directly so auth payloads never carry an empty uuid.
		$uuid = get_user_meta( $user->ID, Pos_Uuid::META_KEY, true );
		if ( ! Pos_Uuid::is_uuid( $uuid ) ) {
			$uuid = wp_generate_uuid4();
			update_user_meta( $user->ID, Pos_Uuid::META_KEY, $uuid );
		}

		return $uuid;
	}

	/**
	 * Adopt a legacy per-blog uuid as the network-wide identity.
	 *
	 * Multisite installs minted `_woocommerce_pos_uuid_{blog_id}` via the old
	 * cashier path. When the plain key holds no valid uuid yet, persist the legacy
	 * value under the plain key so existing cashiers keep their identity; if a
	 * plain uuid already exists it wins, because that is what /customers has
	 * already served to clients. Ownership/duplicate checks still run afterwards
	 * in Pos_Uuid::ensure_user_uuid().
	 *
	 * @param WP_User $user User object.
	 */
	private function maybe_adopt_legacy_multisite_uuid( WP_User $user ): void {
		if ( ! ( \function_exists( 'is_multisite' ) && is_multisite() ) ) {
			return;
		}

		$existing = get_user_meta( $user->ID, Pos_Uuid::META_KEY, true );
		if ( Pos_Uuid::is_uuid( $existing ) ) {
			return;
		}

		$legacy = get_user_meta( $user->ID, Pos_Uuid::META_KEY . '_' . get_current_blog_id(), true );
		if ( Pos_Uuid::is_uuid( $legacy ) ) {
			update_user_meta( $user->ID, Pos_Uuid::META_KEY, $legacy );
		}
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
			'roles'        => array_values( $user->roles ),
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
