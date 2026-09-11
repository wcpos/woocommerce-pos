<?php
/**
 * Access Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;
use WP_Error;
use WP_User;

/**
 * The Access Settings Section.
 *
 * Non-option-backed: read() computes role capabilities from $wp_roles;
 * write() mutates role capabilities via WP_Role::add_cap()/remove_cap().
 * There is no woocommerce_pos_settings_access option.
 */
class Access_Section implements Settings_Section_Interface {
	/**
	 * Section id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'access';
	}

	/**
	 * No option-backed defaults for this section.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array();
	}

	/**
	 * Get the flat list of capability names.
	 *
	 * @return array
	 */
	public static function capability_names(): array {
		$caps = self::get_caps();

		return array_merge( $caps['wcpos'], $caps['wc'], $caps['wp'] );
	}

	/**
	 * Capabilities the user can actually exercise, in the Access-settings vocabulary.
	 *
	 * The user_can() check is what every REST permission callback asks, so it is the
	 * answer the client must be given; it also runs the `user_has_cap` filter that
	 * role editors such as Members use to make a Deny on one role override a grant
	 * on another. The two singular meta caps (edit_product, delete_product) cannot
	 * go through user_can() without a post, so they are read from allcaps after the
	 * same filter has run.
	 *
	 * @param WP_User $user User to report on.
	 *
	 * @return string[] Capability names, in capability_names() order.
	 */
	public static function effective_capabilities( WP_User $user ): array {
		$names = self::capability_names();
		// A multisite super admin holds every capability before the filter runs
		// (WP_User::has_cap), so mirror that bypass for the two filtered names.
		$super_admin = is_multisite() && is_super_admin( $user->ID );

		return array_values(
			array_filter(
				$names,
				function ( $cap ) use ( $user, $super_admin ) {
					if ( ! in_array( $cap, array( 'edit_product', 'delete_product' ), true ) ) {
						return user_can( $user, $cap );
					}
					if ( $super_admin ) {
						return true;
					}
					// Same argument shape as WP_User::has_cap(): the caps being
					// checked, then the requested cap and user id.
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core capability filter, run so role-editor denies apply.
					$filtered = apply_filters( 'user_has_cap', $user->allcaps, array( $cap ), array( $cap, $user->ID ), $user );

					return ! empty( $filtered[ $cap ] );
				}
			)
		);
	}

	/**
	 * Get capabilities grouped by type.
	 *
	 * WooCommerce 9.9 replaced promote_users with create_customers for
	 * customer creation via the REST API. We show the correct capability
	 * on the Access settings page based on the installed WC version.
	 *
	 * The `wc` group lists PRIMITIVE capabilities only — the names a role grant
	 * can actually be read back from. `product` and `shop_coupon` register with
	 * map_meta_cap = true, so WordPress rewrites their singular meta caps
	 * (`edit_shop_coupon`, `delete_shop_coupon`, ...) into the `*_others_*` /
	 * `*_published_*` / `*_private_*` primitives below; exposing the singular
	 * names would be a dead toggle. `edit_product` / `delete_product` are the
	 * exception and ARE required: `product_variation` registers with
	 * capability_type `product` and map_meta_cap = false, so a variation write
	 * checks those two names literally.
	 *
	 * @return array
	 */
	private static function get_caps(): array {
		$customer_create_cap = version_compare( WC()->version, '9.9', '>=' )
			? 'create_customers'
			: 'promote_users';

		return array(
			'wcpos' => array(
				'access_woocommerce_pos',
				'manage_woocommerce_pos',
				'manage_woocommerce_pos_cash',
				'view_woocommerce_pos_reports',
				'manage_woocommerce_pos_closures',
				'reassign_woocommerce_pos_sales',
			),
			'wc' => array(
				$customer_create_cap,
				'read_private_products',
				'edit_product',
				'edit_products',
				'edit_others_products',
				'edit_private_products',
				'edit_published_products',
				'publish_products',
				'delete_product',
				'delete_products',
				'delete_others_products',
				'delete_private_products',
				'delete_published_products',
				'read_private_shop_orders',
				'publish_shop_orders',
				'edit_shop_orders',
				'edit_others_shop_orders',
				'edit_users',
				'list_users',
				'manage_product_terms',
				'read_private_shop_coupons',
				'edit_shop_coupons',
				'edit_others_shop_coupons',
				'edit_private_shop_coupons',
				'edit_published_shop_coupons',
				'publish_shop_coupons',
				'delete_shop_coupons',
				'delete_others_shop_coupons',
				'delete_private_shop_coupons',
				'delete_published_shop_coupons',
			),
			'wp' => array(
				'read',
			),
		);
	}

	/**
	 * Read the section's public view: role capability groups computed from $wp_roles.
	 *
	 * @return array
	 */
	public function read(): array {
		global $wp_roles;
		$role_caps = array();
		$caps      = self::get_caps();

		$roles = $wp_roles->roles;
		if ( $roles ) {
			foreach ( $roles as $slug => $role ) {
				$role_caps[ $slug ] = array(
					'name'         => $role['name'],
					'capabilities' => array(
						'wcpos' => array_intersect_key(
							array_merge( array_fill_keys( $caps['wcpos'], false ), $role['capabilities'] ),
							array_flip( $caps['wcpos'] )
						),
						'wc' => array_intersect_key(
							array_merge( array_fill_keys( $caps['wc'], false ), $role['capabilities'] ),
							array_flip( $caps['wc'] )
						),
						'wp' => array_intersect_key(
							array_merge( array_fill_keys( $caps['wp'], false ), $role['capabilities'] ),
							array_flip( $caps['wp'] )
						),
					),
				);
			}
		}

		/*
		 * Filters the access settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_access_settings
		 */
		return apply_filters( 'woocommerce_pos_access_settings', $role_caps );
	}

	/**
	 * Mutate role capabilities.
	 *
	 * Expects the payload to contain exactly one role slug key. The value is a
	 * partial structure with a 'capabilities' key whose groups (wcpos/wc/wp) map
	 * capability names to boolean grants. Only one role is mutated per call —
	 * this mirrors the single-role update semantics of the original REST
	 * controller.
	 *
	 * The administrator/read capability is never removed as a sanity guard.
	 *
	 * @param array $settings Incoming payload keyed by role slug.
	 *
	 * @return array|WP_Error The fresh read() view on success.
	 */
	public function write( array $settings ) {
		// Defense-in-depth: capability mutation is a privileged service-layer
		// operation; do not rely solely on the REST route's permission
		// callback (matches the Settings::delete_settings() precedent).
		if ( ! current_user_can( 'edit_users' ) || ! current_user_can( 'promote_users' ) ) {
			return new WP_Error(
				'woocommerce_pos_settings_error',
				__( 'You do not have permission to update access settings.', 'woocommerce-pos' ),
				array( 'status' => 403 )
			);
		}

		global $wp_roles;

		// Intersect payload against known role slugs.
		$roles  = array_keys( $wp_roles->roles );
		$update = array_intersect_key( $settings, array_flip( $roles ) );

		// Only update a single role per call.
		if ( 1 === \count( $update ) ) {
			$slugs = array_keys( $update );
			$slug  = $slugs[0];
			$role  = get_role( $slug );

			if ( $role ) {
				// Build the allow-list of capabilities exposed for this role, including
				// extension groups added via the woocommerce_pos_access_settings filter.
				$access_settings = $this->read();
				$allowed_caps    = array();
				if ( isset( $access_settings[ $slug ]['capabilities'] ) ) {
					foreach ( $access_settings[ $slug ]['capabilities'] as $capabilities ) {
						if ( \is_array( $capabilities ) ) {
							$allowed_caps = array_merge( $allowed_caps, array_keys( $capabilities ) );
						}
					}
				}

				// Flatten capability groups (wcpos / wc / wp) into a single map.
				$flattened_caps = array();
				foreach ( $update[ $slug ]['capabilities'] as $capabilities ) {
					if ( \is_array( $capabilities ) ) {
						$flattened_caps = array_merge( $flattened_caps, $capabilities );
					}
				}

				// Ignore capabilities outside the access settings view (issue #1159).
				$flattened_caps = array_intersect_key( $flattened_caps, array_flip( $allowed_caps ) );
				$flattened_caps = array_map( 'wp_validate_boolean', $flattened_caps );

				// Apply each allowed capability grant/revoke.
				foreach ( $flattened_caps as $cap => $grant ) {
					// Sanity check: administrator role must always keep the `read` capability.
					if ( 'administrator' === $slug && 'read' === $cap ) {
						continue;
					}
					if ( $grant ) {
						$role->add_cap( $cap );
					} else {
						$role->remove_cap( $cap );
					}
				}
			}
		}

		return $this->read();
	}

	/**
	 * Full replacement, not a merge: the incoming payload IS the write.
	 *
	 * Writes mutate exactly one role per call and identify that role by the
	 * payload carrying a single known role slug. Merging the existing view in
	 * would hand write() every role on the site and silently turn a capability
	 * update into a no-op, so this section opts out of the default
	 * array_replace_recursive PATCH strategy.
	 *
	 * @param array $existing Existing settings view.
	 * @param array $patch    Incoming payload keyed by role slug.
	 *
	 * @return array
	 */
	public function merge( array $existing, array $patch ): array {
		return $patch;
	}

	/**
	 * REST endpoint args — none required for access.
	 *
	 * @return array
	 */
	public function endpoint_args(): array {
		return array();
	}
}
