<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * THE collection registry (wayfinder #420 spec / #421 implementation): one
 * table for the per-collection identity + capabilities that nine sites
 * currently re-encode in three diverging vocabularies (plural collection,
 * singular object_type, proxy resource slug). Consolidation precedent:
 * trait-endpoint-permissions.php — but a trait shares BEHAVIOUR; this shares
 * DATA, so it is a final class of pure lookups (no WP calls — unit-testable
 * with no bootstrap, like the backfill controller's dispatchers it absorbs).
 *
 * Row shape — capabilities are NULLABLE GROUPS, never booleans: a capability
 * a collection lacks is an ABSENT group, so "does X support Y" is
 * `isset($row['y'])` and a group's fields exist only where they mean
 * something (no empty-string taxonomy on a post collection, no id_type on
 * tax_rates). Groups:
 *
 *  - object_type  — the singular journal vocabulary (always present).
 *  - identity     — the uuid id-space: id_type (post|user|term|order), the
 *                   collection's OWN scalar resolver scope (post_type or
 *                   taxonomy — what resolve_id_by_uuid gets on a push; NOT
 *                   the backfill scan scope), the ownership detector and the
 *                   bulk uuid reader (METHOD NAMES on Pos_Uuid — rows stay
 *                   pure data; consumers resolve callables), and the
 *                   load_entity strategy key. null ⇒ no uuid identity
 *                   (tax_rates, ADR 0009).
 *  - proxy        — the namespaced read route + wc/v3 route + resource slug
 *                   (the slug vocabulary trap: tax_rates' slug is `taxes`).
 *  - write        — push support (the write map's route/id_type projection).
 *  - journal      — the singular object_type the journal emits; orders consume
 *                   it through their payload-windowed pull lane.
 *  - digest       — leg-3 existence digests, present ONLY on the id-space
 *                   OWNER row (products carries product+variation
 *                   object_types; a copy on variations would double-project).
 *  - fingerprint  — config-change detection membership + the barcode flag.
 *  - backfill     — uuid backfill support: the meta-store kind (post, order,
 *                   user, or term) and the SCAN
 *                   scope (products+variations scan together — which is why
 *                   scan_post_types lives here and not on identity).
 *
 * Fail-closed lookups: unknown is null, NEVER a default. The
 * default→products collapse in class-changes-controller.php:662 (a missing
 * case pulls a PRODUCT with another record's id) is the bug class
 * by_object_type() exists to kill — its consumers skip-and-log on null.
 */
final class Collections {

	/** One row per collection, keyed by the canonical plural name. */
	private const ROWS = array(
		'products' => array(
			'object_type' => 'product',
			'identity'    => array(
				'id_type'     => 'post',
				'post_type'   => 'product',
				'detector'    => 'uuid_owned_by_other',
				'bulk_reader' => 'bulk_read_post_uuids',
				'loader'      => 'product',
			),
			'proxy'       => array(
				'route'    => '/products',
				'wc_route' => '/wc/v3/products',
				'slug'     => 'products',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Products_Proxy_Behavior::class,
			),
			'write'       => array( 'route' => '/wc/v3/products' ),
			'journal'     => array( 'object_type' => 'product' ),
			'digest'      => array(
				'id_space' => 'products',
				'object_types' => array( 'product', 'variation' ),
			),
			'fingerprint' => array( 'barcode' => true ),
			'backfill'    => array(
				'kind' => 'post',
				'scan_post_types' => array( 'product', 'product_variation' ),
			),
		),
		'variations' => array(
			'object_type' => 'variation',
			'identity'    => array(
				'id_type'     => 'post',
				'post_type'   => 'product_variation',
				'detector'    => 'uuid_owned_by_other',
				// No bulk reader: variations are stamped through the
				// serialized-product filter, not a proxy list page.
				'bulk_reader' => null,
				'loader'      => 'product',
			),
			'proxy'       => null, // hydrated via the per-id /variations controller
			// Variations use WooCommerce's nested REST resource. The write controller
			// takes the parent from create payloads and the stored object thereafter.
			'write'       => array( 'route' => '/wc/v3/products' ),
			'journal'     => array( 'object_type' => 'variation' ),
			'digest'      => null, // folded into the products id-space (owner row carries it)
			'fingerprint' => array( 'barcode' => true ),
			'backfill'    => array(
				'kind' => 'post',
				'scan_post_types' => array( 'product', 'product_variation' ),
			),
		),
		'orders' => array(
			'object_type' => 'order',
			'identity'    => array(
				'id_type'     => 'order',
				'detector'    => 'uuid_owned_by_other_order',
				'bulk_reader' => null, // payload mode — uuid read from served meta_data
				'loader'      => 'order',
			),
			'proxy'       => array(
				'route'    => '/orders',
				'wc_route' => '/wc/v3/orders',
				'slug'     => 'orders',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Orders_Proxy_Behavior::class,
			),
			'write'       => array( 'route' => '/wc/v3/orders' ),
			'journal'     => array( 'object_type' => 'order' ), // orders consume the journal via the payload-windowed pull lane, catalogue via the pointer stream
			'digest'      => array(
				'id_space' => 'orders',
				'object_types' => array( 'order' ),
			),
			'fingerprint' => null,
			'backfill'    => array( 'kind' => 'order' ),
		),
		'customers' => array(
			'object_type' => 'customer',
			'identity'    => array(
				'id_type'     => 'user',
				'detector'    => 'uuid_owned_by_other_user',
				'bulk_reader' => 'bulk_read_user_uuids',
				'loader'      => 'customer',
			),
			'proxy'       => array(
				'route'    => '/customers',
				'wc_route' => '/wc/v3/customers',
				'slug'     => 'customers',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Customers_Proxy_Behavior::class,
			),
			'write'       => array( 'route' => '/wc/v3/customers' ),
			'journal'     => array( 'object_type' => 'customer' ),
			'digest'      => array(
				'id_space' => 'customers',
				'object_types' => array( 'customer' ),
			),
			'fingerprint' => null,
			'backfill'    => array( 'kind' => 'user' ),
		),
		'categories' => array(
			'object_type' => 'category',
			'identity'    => array(
				'id_type'     => 'term',
				'taxonomy'    => 'product_cat',
				'detector'    => 'uuid_owned_by_other_term',
				'bulk_reader' => 'bulk_read_term_uuids',
				'loader'      => 'term',
			),
			'proxy'       => array(
				'route'    => '/products/categories',
				'wc_route' => '/wc/v3/products/categories',
				'slug'     => 'categories',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Null_Proxy_Behavior::class,
			),
			'write'       => array( 'route' => '/wc/v3/products/categories' ),
			'journal'     => array( 'object_type' => 'category' ),
			'digest'      => null,
			'fingerprint' => null,
			'backfill'    => array(
				'kind' => 'term',
				'taxonomy' => 'product_cat',
			),
		),
		'brands' => array(
			'object_type' => 'brand',
			'identity'    => array(
				'id_type'     => 'term',
				'taxonomy'    => 'product_brand',
				'detector'    => 'uuid_owned_by_other_term',
				'bulk_reader' => 'bulk_read_term_uuids',
				'loader'      => 'term',
			),
			'proxy'       => array(
				'route'    => '/products/brands',
				'wc_route' => '/wc/v3/products/brands',
				'slug'     => 'brands',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Null_Proxy_Behavior::class,
			),
			'write'       => array( 'route' => '/wc/v3/products/brands' ),
			'journal'     => array( 'object_type' => 'brand' ),
			'digest'      => null,
			'fingerprint' => null,
			'backfill'    => array(
				'kind' => 'term',
				'taxonomy' => 'product_brand',
			),
		),
		'tags' => array(
			'object_type' => 'tag',
			'identity'    => array(
				'id_type'     => 'term',
				'taxonomy'    => 'product_tag',
				'detector'    => 'uuid_owned_by_other_term',
				'bulk_reader' => 'bulk_read_term_uuids',
				'loader'      => 'term',
			),
			'proxy'       => array(
				'route'    => '/products/tags',
				'wc_route' => '/wc/v3/products/tags',
				'slug'     => 'tags',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Null_Proxy_Behavior::class,
			),
			'write'       => null, // read-only: no client push path exists
			'journal'     => array( 'object_type' => 'tag' ),
			'digest'      => null,
			'fingerprint' => null,
			'backfill'    => array(
				'kind' => 'term',
				'taxonomy' => 'product_tag',
			),
		),
		'coupons' => array(
			'object_type' => 'coupon',
			'identity'    => array(
				'id_type'     => 'post',
				'post_type'   => 'shop_coupon',
				'detector'    => 'uuid_owned_by_other',
				'bulk_reader' => 'bulk_read_post_uuids',
				'loader'      => 'coupon',
			),
			'proxy'       => array(
				'route'    => '/coupons',
				'wc_route' => '/wc/v3/coupons',
				'slug'     => 'coupons',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Coupons_Proxy_Behavior::class,
			),
			'write'       => array( 'route' => '/wc/v3/coupons' ),
			'journal'     => array( 'object_type' => 'coupon' ),
			'digest'      => null,
			'fingerprint' => null,
			'backfill'    => array(
				'kind' => 'post',
				'scan_post_types' => array( 'shop_coupon' ),
			),
		),
		'tax_rates' => array(
			'object_type' => 'tax_rate',
			'identity'    => null, // ADR 0009: keyed by WooCommerce id — no uuid identity, principled
			'proxy'       => array(
				'route'    => '/taxes',
				'wc_route' => '/wc/v3/taxes',
				'slug'     => 'taxes',
				'behavior' => \WCPOS\WooCommercePOS\API\V2\Proxy\Taxes_Proxy_Behavior::class,
			),
			'write'       => null, // principled read-only
			'journal'     => array( 'object_type' => 'tax_rate' ),
			'digest'      => null,
			'fingerprint' => array( 'barcode' => false ),
			'backfill'    => null, // no meta store to stamp
		),
	);

	/** Row lookup by canonical plural name. Null for unknown — callers decide
	 *  their unsupported behavior EXPLICITLY (no default-to-products, ever). */
	public static function row( string $collection ): ?array {
		return self::ROWS[ $collection ] ?? null;
	}

	/** Inverse lookup: singular change-log object_type → row (+ its plural name
	 *  under '_collection'). `product` resolves EXPLICITLY — it is not a
	 *  fall-through default anywhere in this class. */
	public static function by_object_type( string $object_type ): ?array {
		foreach ( self::ROWS as $collection => $row ) {
			if ( $row['object_type'] === $object_type ) {
				return array( '_collection' => $collection ) + $row;
			}
		}
		return null;
	}

	/** Inverse lookup: proxy resource slug → row (tax_rates' slug is `taxes`). */
	public static function by_proxy_slug( string $slug ): ?array {
		foreach ( self::ROWS as $collection => $row ) {
			if ( isset( $row['proxy'] ) && $row['proxy']['slug'] === $slug ) {
				return array( '_collection' => $collection ) + $row;
			}
		}
		return null;
	}

	/**
	 * Capability projection: every row carrying a non-null `$capability`
	 * group, keyed by plural name. The write map, the proxy RESOURCES, the
	 * digest dispatch and plugin.php's stamper wiring are all projections of
	 * this — adding a collection means adding ONE row above.
	 */
	public static function with( string $capability ): array {
		$rows = array();
		foreach ( self::ROWS as $collection => $row ) {
			if ( isset( $row[ $capability ] ) ) {
				$rows[ $collection ] = $row;
			}
		}
		return $rows;
	}

	/** The full canonical name list (tests, docs, admin surfaces). */
	public static function names(): array {
		return array_keys( self::ROWS );
	}
}
