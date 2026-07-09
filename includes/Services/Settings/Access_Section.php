<?php
/**
 * Access Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;
use WP_Error;

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
	 * Get capabilities grouped by type.
	 *
	 * WooCommerce 9.9 replaced promote_users with create_customers for
	 * customer creation via the REST API. We show the correct capability
	 * on the Access settings page based on the installed WC version.
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
			),
			'wc' => array(
				$customer_create_cap,
				'read_private_products',
				'edit_product',
				'edit_others_products',
				'edit_published_products',
				'read_private_shop_orders',
				'publish_shop_orders',
				'edit_shop_orders',
				'edit_others_shop_orders',
				'edit_users',
				'list_users',
				'manage_product_terms',
				'read_private_shop_coupons',
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
				// Flatten capability groups (wcpos / wc / wp) into a single map.
				$flattened_caps = array();
				foreach ( $update[ $slug ]['capabilities'] as $capabilities ) {
					$flattened_caps = array_merge( $flattened_caps, $capabilities );
				}

				// Apply each capability grant/revoke.
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
	 * PATCH merge for this section (interface default: array_replace_recursive).
	 *
	 * @param array $existing Existing settings view.
	 * @param array $patch    Incoming partial payload.
	 *
	 * @return array
	 */
	public function merge( array $existing, array $patch ): array {
		return array_replace_recursive( $existing, $patch );
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
