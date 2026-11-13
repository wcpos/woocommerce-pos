<?php
/**
 * POS Product Class.
 *
 * @author Paul Kilmurray <paul@kilbot.com>
 *
 * @see    https://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use Automattic\WooCommerce\StoreApi\Exceptions\NotPurchasableException;
use WC_Product;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Query;

/**
 * Products class.
 */
class Products {
	/**
	 * REST namespaces that are the merchant operating the shop, not a shopper browsing it.
	 *
	 * WooCommerce Analytics (`wc-analytics`) and the WooCommerce admin app (`wc-admin`)
	 * read the catalogue to manage and report on it, exactly as wp-admin does, and never
	 * show a product to a shopper. The Store API (`wc/store`) is NOT listed: it renders
	 * the storefront. The core WooCommerce API (`wc/v3`) is not listed either; WC_API
	 * owns that decision.
	 *
	 * @var string[]
	 */
	const OPERATOR_REST_NAMESPACES = array( 'wc-analytics', 'wc-admin' );

	/**
	 * Lower-cased route of the REST request being dispatched, or '' outside REST.
	 *
	 * @var string
	 */
	private $rest_route = '';

	/**
	 * POS Only exclusions built this request, keyed by the post-type selection.
	 *
	 * @var array<string, array{source: array, ids: int[], sql: string}>
	 */
	private $pos_only_exclusions = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$pos_only_products = Settings::instance()->pos_only_products_enabled();

		if ( $pos_only_products ) {
			add_filter( 'rest_pre_dispatch', array( $this, 'record_rest_route' ), 10, 3 );
			// Last, so a later callback that sets suppress_filters or post__in, or edits
			// the WHERE clause, has already run when the exclusion is decided.
			add_action( 'pre_get_posts', array( $this, 'hide_pos_only_products' ), PHP_INT_MAX );
			add_filter( 'posts_where', array( $this, 'exclude_pos_only_products_from_where' ), PHP_INT_MAX, 2 );
			add_filter( 'woocommerce_product_is_visible', array( $this, 'filter_pos_only_product_visibility' ), 10, 2 );
			add_filter( 'woocommerce_variation_is_visible', array( $this, 'hide_pos_only_variations' ), 10, 4 );
			add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'store_api_prevent_pos_only_add_to_cart' ) );

			// NOTE: this hook is marked as deprecated.
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'prevent_pos_only_add_to_cart' ), 10, 2 );

			// add_filter( 'woocommerce_get_product_subcategories_args', array( $this, 'filter_category_count_exclude_pos_only' ) );.
		}

		/**
		 * Allow decimal quantities for products and variations.
		 *
		 * @TODO - this will affect the online store as well, should I make it POS only?
		 */
		$allow_decimal_quantities = Settings::instance()->decimal_qty_enabled();
		if ( $allow_decimal_quantities ) {
			remove_filter( 'woocommerce_stock_amount', 'intval' );
			add_filter( 'woocommerce_stock_amount', 'floatval' );
			add_action( 'woocommerce_before_product_object_save', array( $this, 'save_decimal_quantities' ) );
		}
	}

	/**
	 * Remember which REST route is being dispatched.
	 *
	 * `pre_get_posts` cannot see the request, and the `rest_route` query var is
	 * not set when a request is dispatched directly (batch sub-requests, tests), so
	 * the route is captured here, the same way WC_API does.
	 *
	 * @param mixed            $result  The dispatch result.
	 * @param \WP_REST_Server  $server  The server instance.
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return mixed The unchanged result.
	 */
	public function record_rest_route( $result, $server, $request ) {
		// WordPress matches routes case-insensitively, so compare lower-cased.
		$this->rest_route = strtolower( $request->get_route() );

		return $result;
	}

	/**
	 * Get POS Only product and variation ids for the query post types.
	 *
	 * This is the hard backstop (`post__not_in`). Since #1862 a POS Only product
	 * also carries WooCommerce catalog visibility `hidden`, kept in step by
	 * {@see Catalog_Visibility}, so surfaces that never run this query filter
	 * (blocks, Store API, related products, wc/v3) hide it too.
	 *
	 * The list and its SQL are built once per post-type selection and rebuilt only
	 * when the filtered settings values differ (`===` on an unchanged array is a
	 * pointer check), so a settings write or filter change mid-request still shows.
	 *
	 * @param \WP_Query $query The WP_Query instance (passed by reference).
	 *
	 * @return array{ids: int[], sql: string} The ids, and the WHERE fragment that excludes them ('' when none).
	 */
	private function get_pos_only_excluded_ids( \WP_Query $query ): array {
		global $wpdb;

		// WP_Query post_type can be string or array.
		$post_types = (array) $query->get( 'post_type' );
		$settings   = Settings::instance();
		$lists      = array();
		if ( \in_array( 'product', $post_types, true ) ) {
			$lists['product'] = $settings->get_pos_only_product_visibility_settings()['ids'] ?? null;
		}
		if ( \in_array( 'product_variation', $post_types, true ) ) {
			$lists['product_variation'] = $settings->get_pos_only_variations_visibility_settings()['ids'] ?? null;
		}
		if ( empty( $lists ) ) {
			return array(
				'ids' => array(),
				'sql' => '',
			);
		}

		$key    = implode( ',', array_keys( $lists ) );
		$source = array( $wpdb->posts, $lists );
		if ( ! isset( $this->pos_only_exclusions[ $key ] ) || $this->pos_only_exclusions[ $key ]['source'] !== $source ) {
			$ids = array();
			foreach ( $lists as $list ) {
				if ( ! empty( $list ) ) {
					$ids = array_merge( $ids, array_map( 'intval', (array) $list ) );
				}
			}
			// The ids are int-cast, so the fragment is safe without one placeholder per id.
			$this->pos_only_exclusions[ $key ] = array(
				'source' => $source,
				'ids'    => $ids,
				'sql'    => empty( $ids ) ? '' : " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', $ids ) . ')',
			);
		}

		return $this->pos_only_exclusions[ $key ];
	}

	/**
	 * Write the POS Only ids into `post__not_in` on every storefront product query.
	 *
	 * Code that answers from the query vars (`posts_pre_query` integrations such as
	 * ElasticPress, Relevanssi, SearchWP, Algolia) honours them, and WordPress
	 * builds the `NOT IN` itself. Its `post__in` is trimmed too, because WordPress
	 * then ignores `post__not_in`.
	 *
	 * @param \WP_Query $query The query being filtered.
	 */
	public function hide_pos_only_products( \WP_Query $query ): void {
		if ( ! $this->should_hide_pos_only_products( $query ) ) {
			return;
		}
		$exclude_ids = $this->get_pos_only_excluded_ids( $query )['ids'];
		if ( empty( $exclude_ids ) ) {
			return;
		}

		// WordPress ignores post__not_in whenever post__in is set, and a
		// get_posts() caller suppresses the posts_where clause below as well, so
		// an id list is trimmed here. An emptied list keeps an impossible id:
		// an empty post__in would turn the query into an unrestricted one.
		$post__in = (array) $query->get( 'post__in' );
		if ( ! empty( $post__in ) ) {
			$post__in = array_values( array_diff( array_map( 'intval', $post__in ), $exclude_ids ) );
			$query->set( 'post__in', empty( $post__in ) ? array( 0 ) : $post__in );
		}

		$existing_excludes = $query->get( 'post__not_in' );
		if ( ! \is_array( $existing_excludes ) ) {
			$existing_excludes = array();
		}
		$query->set( 'post__not_in', array_merge( $existing_excludes, $exclude_ids ) );
	}

	/**
	 * Write the POS Only exclusion into a filtered query's WHERE clause where WordPress left it out.
	 *
	 * The clause is written only where WordPress left `post__not_in` out of the
	 * WHERE (`p` or `post__in` set) or a later callback stripped it, so a query
	 * carries the list once. It is also written when `post__not_in` no longer
	 * holds every POS Only id (a later callback replaced or cleared it).
	 *
	 * @param string    $where The SQL WHERE clause.
	 * @param \WP_Query $query The query being filtered.
	 */
	public function exclude_pos_only_products_from_where( $where, $query ) {
		global $wpdb;

		if ( $this->should_hide_pos_only_products( $query ) ) {
			$exclusion = $this->get_pos_only_excluded_ids( $query );
			if ( $query->get( 'p' ) || ! empty( $query->get( 'post__in' ) )
				|| array_diff( $exclusion['ids'], array_map( 'absint', (array) $query->get( 'post__not_in' ) ) )
				|| false === strpos( $where, " AND {$wpdb->posts}.ID NOT IN (" ) ) {
				$where .= $exclusion['sql'];
			}
		}

		return $where;
	}

	/**
	 * Cover related products, upsells, cross-sells and classic theme loops,
	 * which never run the query filter. Use the same gate as the query filter
	 * minus the per-query hook.
	 *
	 * @param bool $visible    Whether the product is visible.
	 * @param int  $product_id The product or variation ID.
	 */
	public function filter_pos_only_product_visibility( $visible, $product_id ) {
		if ( ! $visible || ! $this->is_storefront_request() ) {
			return $visible;
		}

		$settings = Settings::instance();
		return $settings->is_product_pos_only( $product_id ) || $settings->is_variation_pos_only( $product_id ) ? false : $visible;
	}

	/**
	 * Whether the request is a storefront rather than an operator context.
	 */
	private function is_storefront_request(): bool {
		return ! ( is_admin() || wcpos_request() || $this->is_cli_request() || $this->is_operator_rest_request() );
	}

	/**
	 * Whether the POS Only exclusion applies to the request running this query.
	 *
	 * Hiding POS Only products is a storefront rule: a shopper must not see or buy
	 * them. It does not apply where the merchant is operating the shop rather than
	 * shopping in it (wp-admin, WP-CLI, the WooCommerce admin REST namespaces), nor
	 * on POS requests, which are what POS Only products exist for. Applying it to
	 * WooCommerce Analytics, which resolves a category filter with wc_get_products()
	 * during a wc-analytics request, made a category of POS Only products report
	 * "No data" while the same products' sales showed when searched by name.
	 *
	 * @param \WP_Query $query The query being decided.
	 *
	 * @return bool
	 */
	public function should_hide_pos_only_products( \WP_Query $query ): bool {
		$hide = $this->is_storefront_request();

		/**
		 * Filters whether POS Only products are excluded from a product query.
		 *
		 * The default is true on the storefront (catalogue, search, the Store API,
		 * feeds and sitemaps) and false in wp-admin, WP-CLI, the WooCommerce admin
		 * REST namespaces and the POS itself. Return false to let a context of
		 * your own see POS Only products.
		 *
		 * @param bool      $hide  Whether to exclude POS Only products from this query.
		 * @param \WP_Query $query The query being decided.
		 */
		$filtered = apply_filters( 'wcpos_hide_pos_only_products', $hide, $query );
		return (bool) apply_filters_deprecated( 'woocommerce_pos_hide_pos_only_products', array( $filtered, $query ), '2.0.0', 'wcpos_hide_pos_only_products' );
	}

	/**
	 * Whether the current REST request belongs to an operator namespace.
	 *
	 * @return bool
	 */
	private function is_operator_rest_request(): bool {
		if ( '' === $this->rest_route ) {
			return false;
		}
		foreach ( self::OPERATOR_REST_NAMESPACES as $namespace ) {
			if ( '/' . $namespace === $this->rest_route || 0 === strpos( $this->rest_route, '/' . $namespace . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the request is a WP-CLI command.
	 *
	 * @return bool
	 */
	private function is_cli_request(): bool {
		return \defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Remove POS Only variations from the storefront.
	 *
	 * @param bool                 $visible      Whether the variation is visible.
	 * @param int                  $variation_id The variation ID.
	 * @param int                  $product_id   The product ID.
	 * @param WC_Product_Variation $variation    The variation object.
	 */
	public function hide_pos_only_variations( $visible, $variation_id, $product_id, $variation ) {
		if ( is_shop() || is_product_category() || is_product() ) {
			$settings_instance = Settings::instance();
			$pos_only          = $settings_instance->is_variation_pos_only( $variation_id );

			if ( $pos_only ) {
				return false;
			}
		}

		return $visible;
	}

	/**
	 * Filter category count to exclude pos_only products.
	 *
	 * @param array $args The arguments for getting product subcategories.
	 *
	 * @return array The modified arguments.
	 */
	public function filter_category_count_exclude_pos_only( $args ) {
		// @TODO: Do we need this?

		return $args;
	}

	/**
	 * Prevent POS Only products from being added to the cart.
	 *
	 * NOTE: this hook is marked as deprecated.
	 *
	 * @param bool $passed     Whether the product can be added to the cart.
	 * @param int  $product_id The product ID.
	 *
	 * @return bool
	 */
	public function prevent_pos_only_add_to_cart( $passed, $product_id ) {
		$settings_instance  = Settings::instance();
		$product_pos_only   = $settings_instance->is_product_pos_only( $product_id );
		$variation_pos_only = $settings_instance->is_variation_pos_only( $product_id );

		if ( $product_pos_only || $variation_pos_only ) {
			return false;
		}

		return $passed;
	}

	/**
	 * Prevent POS Only products from being added to the cart via the Store API.
	 *
	 * @param WC_Product $product Product.
	 *
	 * @throws NotPurchasableException If the product is POS only.
	 *
	 * @return void
	 */
	public function store_api_prevent_pos_only_add_to_cart( WC_Product $product ): void {
		$settings_instance = Settings::instance();
		if ( $product->is_type( 'variation' ) ) {
			$pos_only = $settings_instance->is_variation_pos_only( $product->get_id() );
		} else {
			$pos_only = $settings_instance->is_product_pos_only( $product->get_id() );
		}

		if ( $pos_only ) {
			// @phpstan-ignore-next-line.
			throw new NotPurchasableException(
				'woocommerce_pos_product_not_purchasable',
				esc_html( $product->get_name() )
			);
		}
	}

	/**
	 * Save decimal quantities for products and variations.
	 *
	 * @param WC_Product $product Product.
	 */
	public function save_decimal_quantities( WC_Product $product ): void {
		// Without stock management there is no quantity to derive a status from;
		// WooCommerce respects the manually chosen stock status, so leave it untouched.
		if ( ! $product->get_manage_stock() ) {
			return;
		}

		$stock_quantity               = $product->get_stock_quantity();
		$stock_notification_threshold = absint( get_option( 'woocommerce_notify_no_stock_amount', 0 ) );

		// Mirrors WC_Product::validate_props() minus its (int) cast on the stock
		// quantity, which would truncate fractional stock (e.g. 0.5) to 0 and
		// mark it out of stock. Re-check against core on WooCommerce updates.
		$stock_is_above_notification_threshold = ( $stock_quantity > $stock_notification_threshold );
		$backorders_are_allowed                = ( 'no' !== $product->get_backorders() );

		if ( $stock_is_above_notification_threshold ) {
			$new_stock_status = 'instock';
		} elseif ( $backorders_are_allowed ) {
			$new_stock_status = 'onbackorder';
		} else {
			$new_stock_status = 'outofstock';
		}

		$product->set_stock_status( $new_stock_status );
	}
}
