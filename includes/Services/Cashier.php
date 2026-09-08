<?php
/**
 * Cashier.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Abstracts\Store;
use WCPOS\WooCommercePOS\Services\Settings\Access_Section;
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
	 * a divergent value makes one person appear as two. Legacy multisite per-blog
	 * uuids (minted by an old version of this method) are adopted network-wide by
	 * the authority.
	 *
	 * @param WP_User $user User object.
	 *
	 * @return string UUID for the cashier ('' only if WooCommerce customer data is unavailable).
	 */
	public function get_cashier_uuid( WP_User $user ): string {
		return Pos_Uuid::ensure_user_uuid( $user );
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
			// The helper reports effective grants, including role-editor denies.
			'capabilities' => Access_Section::effective_capabilities( $user ),
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
	 * POS baseline capabilities the user lacks.
	 *
	 * The baseline is what the POS needs to open and take a sale: the access gate,
	 * the cashier gate (publish_shop_orders — see has_cashier_permissions()), and the
	 * three reads every screen makes. Missing entries are reported, in this order,
	 * so a merchant can see which role or role-editor deny is responsible.
	 *
	 * @param WP_User $user User to check.
	 * @return string[] Missing capability names.
	 */
	public function missing_pos_capabilities( WP_User $user ): array {
		$baseline = array( 'access_woocommerce_pos', 'publish_shop_orders', 'read_private_products', 'read_private_shop_orders', 'list_users' );

		return array_values( array_filter( $baseline, fn( $cap ) => ! user_can( $user, $cap ) ) );
	}

	/**
	 * Whether the user clears the two gates the server already enforces.
	 *
	 * Only access_woocommerce_pos (the REST gate) and publish_shop_orders (the
	 * cashier gate) block entry; a user missing only a read capability can still
	 * work partially, and the corrected capability payload tells the client what
	 * is missing.
	 *
	 * @param WP_User $user User to check.
	 * @return bool True when both entry capabilities are granted.
	 */
	public function can_open_pos( WP_User $user ): bool {
		$blocking = array_intersect( array( 'access_woocommerce_pos', 'publish_shop_orders' ), $this->missing_pos_capabilities( $user ) );

		return empty( $blocking );
	}

	/**
	 * Describe missing baseline capabilities and how to grant them.
	 *
	 * @param WP_User $user User to check.
	 * @return string Diagnostic message, or empty unless the user is blocked by can_open_pos().
	 */
	public function missing_pos_capabilities_message( WP_User $user ): string {
		if ( $this->can_open_pos( $user ) ) {
			return '';
		}
		$missing = $this->missing_pos_capabilities( $user );
		/* translators: %s: Comma-separated missing capability names. */
		$message = sprintf( __( 'This account cannot use the POS. Missing capabilities: %s.', 'woocommerce-pos' ), implode( ', ', $missing ) );
		if ( count( $user->roles ) >= 2 ) {
			$role_names = array_map(
				function ( $slug ) {
					$roles = wp_roles()->roles;

					return isset( $roles[ $slug ]['name'] ) ? translate_user_role( $roles[ $slug ]['name'] ) : $slug;
				},
				$user->roles
			);
			/* translators: %s: Comma-separated role names. */
			return $message . ' ' . sprintf( __( 'It has the roles %s. A capability denied on one role can override a grant from another, and role-editor plugins such as Members apply that deny first. Remove the extra role or clear the deny in the role editor.', 'woocommerce-pos' ), implode( ', ', $role_names ) );
		}

		/* translators: Guidance for granting missing POS capabilities. */
		return $message . ' ' . __( 'Grant them under WCPOS Settings, Access, or assign a role that has them.', 'woocommerce-pos' );
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
