<?php
/**
 * The POS capability vocabulary and default grants.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Declares each POS capability once, in API order. */
final class Permission_Registry {
	/**
	 * Capabilities introduced before this version predate the marker and are handled
	 * by the per-role snapshot; capabilities introduced at or after it are granted once by the marker.
	 */
	public const MARKER_BASELINE = '2.2.0';

	/** @var array[] Entries appended by tests. */
	private static $test_entries = array();

	/**
	 * Get entries without loading translations during activation.
	 *
	 * @return array[] Capability, row, group, kind, tier, defaults, introduced and split_from.
	 */
	public static function entries(): array {
		$managers            = array( 'administrator', 'shop_manager' );
		$all                 = array( 'administrator', 'shop_manager', 'cashier' );
		$cashier             = array( 'cashier' );
		// WC 9.9 replaced promote_users with create_customers for customer creation.
		$customer_create_cap = \defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' ) // @phpstan-ignore-line
			? 'create_customers'
			: 'promote_users';
		// Capability => row, group, default roles, in the existing API order.
		$rows = array(
			'access_woocommerce_pos'          => array( 'use_pos', 'pos', $all ),
			'manage_woocommerce_pos'          => array( 'manage_settings', 'pos', $managers ),
			'manage_woocommerce_pos_cash'     => array( 'manage_cash', 'cash', $all ),
			'view_woocommerce_pos_reports'    => array( 'reports', 'pages', $all ),
			'manage_woocommerce_pos_closures' => array( 'approve_variance', 'cash', $managers ),
			'reassign_woocommerce_pos_sales'  => array( 'reassign_sales', 'after_sale', $managers ),
			$customer_create_cap              => array( 'add_customer', 'customers', $cashier ),
			'read_private_products'           => array( 'edit_products', 'products', $cashier ),
			'edit_product'                    => array( 'edit_products', 'products', $cashier ),
			'edit_products'                   => array( 'edit_products', 'products', $cashier ),
			'edit_others_products'            => array( 'edit_products', 'products', $cashier ),
			'edit_private_products'           => array( 'edit_products', 'products', $cashier ),
			'edit_published_products'         => array( 'edit_products', 'products', $cashier ),
			'publish_products'                => array( 'edit_products', 'products', $cashier ),
			'delete_product'                  => array( 'delete_products', 'products', array() ),
			'delete_products'                 => array( 'delete_products', 'products', array() ),
			'delete_others_products'          => array( 'delete_products', 'products', array() ),
			'delete_private_products'         => array( 'delete_products', 'products', array() ),
			'delete_published_products'       => array( 'delete_products', 'products', array() ),
			'read_private_shop_orders'        => array( 'pos_prerequisites', 'pos', $cashier ),
			'publish_shop_orders'             => array( 'pos_prerequisites', 'pos', $cashier ),
			'edit_shop_orders'                => array( 'pos_prerequisites', 'pos', $cashier ),
			'edit_others_shop_orders'         => array( 'pos_prerequisites', 'pos', $cashier ),
			'edit_users'                      => array( 'edit_customer', 'customers', $cashier ),
			'list_users'                      => array( 'pos_prerequisites', 'pos', $cashier ),
			'manage_product_terms'            => array( 'manage_categories', 'products', $cashier ),
			'read_private_shop_coupons'       => array( 'manage_coupons', 'products', $cashier ),
			'edit_shop_coupons'               => array( 'manage_coupons', 'products', $cashier ),
			'edit_others_shop_coupons'        => array( 'manage_coupons', 'products', $cashier ),
			'edit_private_shop_coupons'       => array( 'manage_coupons', 'products', $cashier ),
			'edit_published_shop_coupons'     => array( 'manage_coupons', 'products', $cashier ),
			'publish_shop_coupons'            => array( 'manage_coupons', 'products', $cashier ),
			'delete_shop_coupons'             => array( 'delete_coupons', 'products', array() ),
			'delete_others_shop_coupons'      => array( 'delete_coupons', 'products', array() ),
			'delete_private_shop_coupons'     => array( 'delete_coupons', 'products', array() ),
			'delete_published_shop_coupons'   => array( 'delete_coupons', 'products', array() ),
			'read'                            => array( 'pos_prerequisites', 'pos', $cashier ),
		);
		$entries = array();
		foreach ( $rows as $capability => list( $row, $group, $defaults ) ) {
			$entries[] = array(
				'capability' => $capability,
				'row'        => $row,
				'group'      => $group,
				'kind'       => 'reports' === $row ? 'page' : 'action',
				'tier'       => 'store',
				'defaults'   => $defaults,
				'introduced' => '2.0.0',
				'split_from' => null,
			);
		}
		return array_merge( $entries, self::$test_entries );
	}

	/** Get capability names in registry order. */
	public static function capability_names(): array {
		return array_column( self::entries(), 'capability' );
	}

	/**
	 * Get the legacy API group for a capability.
	 *
	 * @param string $capability Capability name.
	 */
	public static function api_group( string $capability ): string {
		if ( 'read' === $capability ) {
			return 'wp';
		}
		return false !== strpos( $capability, 'woocommerce_pos' ) ? 'wcpos' : 'wc';
	}

	/**
	 * Get a role's default grants in registry order.
	 *
	 * @param string $role Role slug.
	 */
	public static function defaults_for_role( string $role ): array {
		$defaults = array();
		foreach ( self::entries() as $entry ) {
			if ( in_array( $role, $entry['defaults'], true ) ) {
				$defaults[] = $entry['capability'];
			}
		}
		return $defaults;
	}

	/** Get the translated labels for owner-facing rows. */
	public static function labels(): array {
		return array(
			'use_pos'           => __( 'Use the POS', 'woocommerce-pos' ),
			'manage_settings'   => __( 'Manage POS settings in WP Admin', 'woocommerce-pos' ),
			'manage_cash'       => __( 'Open and close the register, cash in and out', 'woocommerce-pos' ),
			'reports'           => __( 'Reports', 'woocommerce-pos' ),
			'approve_variance'  => __( 'Approve a variance or recount', 'woocommerce-pos' ),
			'reassign_sales'    => __( "Reassign a sale's cashier", 'woocommerce-pos' ),
			'add_customer'      => __( 'Add a customer', 'woocommerce-pos' ),
			'edit_products'     => __( 'Edit products', 'woocommerce-pos' ),
			'delete_products'   => __( 'Delete products', 'woocommerce-pos' ),
			'edit_customer'     => __( 'Edit a customer', 'woocommerce-pos' ),
			'manage_categories' => __( 'Manage categories', 'woocommerce-pos' ),
			'manage_coupons'    => __( 'Manage coupons', 'woocommerce-pos' ),
			'delete_coupons'    => __( 'Delete coupons', 'woocommerce-pos' ),
		);
	}

	/**
	 * Append a test capability.
	 *
	 * @internal Tests only.
	 * @param array $entry Entry with at least a capability name.
	 */
	public static function add_test_entry( array $entry ): void {
		self::$test_entries[] = $entry + array(
			'row'        => $entry['capability'],
			'group'      => 'pos',
			'kind'       => 'action',
			'tier'       => 'store',
			'defaults'   => array(),
			'introduced' => self::MARKER_BASELINE,
			'split_from' => null,
		);
	}

	/**
	 * Clear appended entries.
	 *
	 * @internal Tests only.
	 */
	public static function reset_test_entries(): void {
		self::$test_entries = array();
	}
}
