<?php
/**
 * WCPOS POS visibility authority.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\Services\Settings;

/**
 * POS visibility contract (ADR 0014 phase 3, WP-M5) — the SOLE authority for "which products and
 * variations may the POS be served".
 *
 * Every read lane (wcpos/v1 WP_Query + bulk-id SQL, wcpos/v2 catalog proxy, variations, resolve,
 * integrity, and the variable-price range helper) asks THIS class instead of re-deriving the rule.
 *
 * # Storage
 *
 * A SINGLE wp_option `woocommerce_pos_settings_visibility` holding ID LISTS — NOT the per-record
 * `_pos_visibility` postmeta that older versions used (that was removed to avoid spamming postmeta).
 * Shape:
 *
 *   woocommerce_pos_settings_visibility = array(
 *     'products'   => array( <scope> => array(
 *        'pos_only'    => array( 'ids' => array( 1, 2, 3 ) ),   // hidden from the WEB store
 *        'online_only' => array( 'ids' => array( 4, 5, 6 ) ),   // hidden from the POS  <-- what we filter on
 *     ) ),
 *     'variations' => array( <scope> => array( 'pos_only' => ..., 'online_only' => ... ) ),
 *   )
 *
 * `<scope>` is `'default'` or a store id (multi-store, per-store visibility). The POS servable set is
 * every product/variation EXCEPT the `online_only` ids for the scope; `pos_only` is a web-store
 * concern, not ours. An empty/absent option means nothing is hidden — the safe default (feature
 * simply off).
 *
 * # Reads go through the Settings service
 *
 * The option is NEVER read directly here. `Services\Settings` owns the Visibility section's
 * defaults-merge and migration, and applies the public extension filters
 * (`woocommerce_pos_product_visibility_settings`, `woocommerce_pos_variations_visibility_settings`,
 * `woocommerce_pos_online_only_product_visibility_settings`,
 * `woocommerce_pos_online_only_variations_visibility_settings`). Reading the raw option would bypass
 * all of it, so an extension that hides a product would be honoured on some lanes and ignored on
 * others. Routing every lane through this class routes every lane through those filters.
 *
 * # Feature gate
 *
 * The `pos_only_products` toggle (General settings, `Settings::pos_only_products_enabled()`) is
 * enforced INSIDE this module: when the feature is off every method here reports an empty hidden set
 * and leaves its input untouched. Callers must not re-implement the gate to decide the servable set;
 * a caller may still short-circuit on the gate purely to avoid attaching a filter it knows will do
 * nothing.
 */
final class Pos_Visibility {
	/**
	 * The single wp_option the POS app writes (db_prefix `woocommerce_pos_settings_` + `visibility`).
	 *
	 * @var string
	 */
	public const OPTION = 'woocommerce_pos_settings_visibility';

	/**
	 * Hidden-set type: products only.
	 *
	 * @var string
	 */
	public const PRODUCTS = 'products';

	/**
	 * Hidden-set type: variations only.
	 *
	 * @var string
	 */
	public const VARIATIONS = 'variations';

	/**
	 * Hidden-set type: products UNION variations.
	 *
	 * Products and variations share the `wp_posts` id-space, so the two lists union safely whenever a
	 * query can return either post type.
	 *
	 * @var string
	 */
	public const CATALOG = 'catalog';

	/**
	 * The scope used when a caller does not ask for a store-specific one.
	 *
	 * @var string
	 */
	public const DEFAULT_SCOPE = 'default';

	/**
	 * The ids hidden from the POS for one type and scope — the servable-set exclusion list.
	 *
	 * Returns unique positive ints in stored order. Empty whenever the `pos_only_products` feature is
	 * off, the option is absent, or the scope was never configured, so a missing option can never
	 * accidentally hide the whole catalog.
	 *
	 * @param string      $type  One of self::PRODUCTS, self::VARIATIONS, self::CATALOG. The singular
	 *                           forms `product` / `variation` are accepted as aliases.
	 * @param null|string $scope Visibility scope: `default` or a store id. Null means default.
	 *
	 * @return int[]
	 */
	public function hidden_ids( string $type, ?string $scope = null ): array {
		if ( ! Settings::instance()->pos_only_products_enabled() ) {
			return array();
		}

		$type  = self::normalize_type( $type );
		$scope = self::normalize_scope( $scope );

		if ( self::CATALOG === $type ) {
			return array_values(
				array_unique(
					array_merge(
						$this->online_only_ids( self::PRODUCTS, $scope ),
						$this->online_only_ids( self::VARIATIONS, $scope )
					)
				)
			);
		}

		return $this->online_only_ids( $type, $scope );
	}

	/**
	 * Apply the exclusion to a WP_Query / WC REST `*_object_query` argument array.
	 *
	 * Owns the `post__in` trap: WooCommerce maps `include=` to `post__in`, and WP_Query IGNORES
	 * `post__not_in` whenever `post__in` is present (they are mutually exclusive in core's WHERE
	 * builder). So for a targeted pull we must INTERSECT — subtract the hidden ids from the include
	 * list itself — or a targeted pull of a hidden id would leak. An empty intersection must yield an
	 * EMPTY response, not an unfiltered one, so `post__in` is pinned to a non-existent id (0) rather
	 * than left empty.
	 *
	 * The `products` collection excludes hidden PRODUCTS AND VARIATIONS: WooCommerce widens
	 * `post_type` to `product_variation` on SKU-ish params (sku/search_sku/search_name_or_sku/
	 * search_fields), so variation rows can ride a `/products` response. The union is harmless when
	 * `post_type` is not widened because variation ids never match a plain product query.
	 *
	 * @param array       $args       Query args to filter.
	 * @param string      $collection Collection being queried: `products` or `variations`. Any other
	 *                                value returns the args untouched.
	 * @param null|string $scope      Visibility scope: `default` or a store id. Null means default.
	 *
	 * @return array
	 */
	public function apply_to_wp_query_args( array $args, string $collection, ?string $scope = null ): array {
		$type = self::collection_type( $collection );
		if ( null === $type ) {
			return $args;
		}

		$exclude = $this->hidden_ids( $type, $scope );
		if ( array() === $exclude ) {
			return $args;
		}

		if ( isset( $args['post__in'] ) && array() !== (array) $args['post__in'] ) {
			$intersected      = array_values( array_diff( array_map( 'intval', (array) $args['post__in'] ), $exclude ) );
			$args['post__in'] = array() === $intersected ? array( 0 ) : $intersected;

			return $args;
		}

		$existing             = isset( $args['post__not_in'] ) && \is_array( $args['post__not_in'] ) ? $args['post__not_in'] : array();
		$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $exclude ) ) );

		return $args;
	}

	/**
	 * Append the exclusion to a SQL WHERE clause for the bulk-id lanes.
	 *
	 * The returned fragment is already prepared, so the caller must NOT re-run the hidden-id values
	 * through `$wpdb->prepare()`. A lane that assembles placeholders and defers `prepare()` to the end
	 * should call `hidden_ids()` and build its own `IN` list instead.
	 *
	 * @param string      $where     The WHERE clause (or partial SQL) to extend.
	 * @param string      $id_column The fully qualified id column to test, e.g. `wp_posts.ID`. Caller
	 *                               supplied and never derived from a request.
	 * @param string      $type      One of self::PRODUCTS, self::VARIATIONS, self::CATALOG.
	 * @param null|string $scope     Visibility scope: `default` or a store id. Null means default.
	 *
	 * @return string
	 */
	public function apply_to_sql_where( string $where, string $id_column, string $type, ?string $scope = null ): string {
		global $wpdb;

		$hidden = $this->hidden_ids( $type, $scope );
		if ( array() === $hidden ) {
			return $where;
		}

		$ids_format = implode( ',', array_fill( 0, \count( $hidden ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name is caller-supplied code, ids use %d placeholders.
		return $where . $wpdb->prepare( " AND {$id_column} NOT IN ($ids_format) ", $hidden );
	}

	/**
	 * Drop POS-hidden variations from a list of variation ids.
	 *
	 * WooCommerce's own visible-children resolution applies the store's WEB visibility rules, NOT the
	 * POS exclusion, so a child hidden from the POS would otherwise leak — its price into a served
	 * price range, or the row itself into a targeted pull. Also used for `include=` variation lists.
	 *
	 * @param array       $ids   Variation ids.
	 * @param null|string $scope Visibility scope: `default` or a store id. Null means default.
	 *
	 * @return int[]
	 */
	public function filter_visible_children( array $ids, ?string $scope = null ): array {
		$ids    = array_values( array_map( 'intval', $ids ) );
		$hidden = $this->hidden_ids( self::VARIATIONS, $scope );

		if ( array() === $hidden ) {
			return $ids;
		}

		return array_values( array_diff( $ids, $hidden ) );
	}

	/**
	 * Product ids hidden from the POS for the given scope.
	 *
	 * @deprecated Use hidden_ids( self::PRODUCTS, $scope ). Kept as a thin delegate so callers outside
	 *             this repository keep working.
	 *
	 * @param string $scope Visibility scope: `default` or a store id.
	 *
	 * @return int[]
	 */
	public function online_only_product_ids( string $scope = self::DEFAULT_SCOPE ): array {
		return $this->hidden_ids( self::PRODUCTS, $scope );
	}

	/**
	 * Variation ids hidden from the POS for the given scope.
	 *
	 * @deprecated Use hidden_ids( self::VARIATIONS, $scope ). Kept as a thin delegate so callers
	 *             outside this repository keep working.
	 *
	 * @param string $scope Visibility scope: `default` or a store id.
	 *
	 * @return int[]
	 */
	public function online_only_variation_ids( string $scope = self::DEFAULT_SCOPE ): array {
		return $this->hidden_ids( self::VARIATIONS, $scope );
	}

	/**
	 * Map a collection slug to the hidden-set type it must exclude.
	 *
	 * @param string $collection Collection slug.
	 *
	 * @return null|string Null when the collection carries no POS visibility rule.
	 */
	private static function collection_type( string $collection ): ?string {
		switch ( $collection ) {
			case 'products':
			case self::CATALOG:
				return self::CATALOG;
			case 'variation':
			case self::VARIATIONS:
				return self::VARIATIONS;
			default:
				return null;
		}
	}

	/**
	 * Accept the singular aliases for the stored post-type keys.
	 *
	 * @param string $type Requested type.
	 *
	 * @return string
	 */
	private static function normalize_type( string $type ): string {
		switch ( $type ) {
			case 'product':
			case self::PRODUCTS:
				return self::PRODUCTS;
			case 'variation':
			case self::VARIATIONS:
				return self::VARIATIONS;
			default:
				return self::CATALOG;
		}
	}

	/**
	 * Null or empty scope means the default scope.
	 *
	 * @param null|string $scope Requested scope.
	 *
	 * @return string
	 */
	private static function normalize_scope( ?string $scope ): string {
		return ( null === $scope || '' === $scope ) ? self::DEFAULT_SCOPE : $scope;
	}

	/**
	 * The `online_only` id list for one post-type + scope, read through the Settings service so the
	 * section's defaults-merge, migration and the public visibility filters all apply.
	 *
	 * Every level of the stored shape can be missing (POS app not installed, scope never configured,
	 * key absent). The Settings accessors index `[ $post_type ][ $scope ][ 'online_only' ]` directly,
	 * so the path is checked here first — a missing one yields an empty list rather than a PHP warning.
	 *
	 * @param string $post_type `products` or `variations`.
	 * @param string $scope     Visibility scope.
	 *
	 * @return int[]
	 */
	private function online_only_ids( string $post_type, string $scope ): array {
		$settings = Settings::instance();
		$stored   = $settings->get_visibility_settings();

		if ( ! isset( $stored[ $post_type ][ $scope ]['online_only'] ) ) {
			return array();
		}

		$view = self::PRODUCTS === $post_type
			? $settings->get_online_only_product_visibility_settings( $scope )
			: $settings->get_online_only_variations_visibility_settings( $scope );

		$ids = \is_array( $view ) && isset( $view['ids'] ) ? $view['ids'] : array();
		if ( ! \is_array( $ids ) ) {
			return array();
		}

		$ids = array_filter(
			array_map( 'intval', $ids ),
			static function ( $id ) {
				return $id > 0;
			}
		);

		return array_values( array_unique( $ids ) );
	}
}
