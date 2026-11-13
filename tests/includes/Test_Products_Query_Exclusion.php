<?php
/**
 * Real-query tests for the POS Only exclusion in the Products class.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Query;
use WP_REST_Request;

/**
 * Test_Products_Query_Exclusion class.
 *
 * Each test runs real product queries, so it pins what WordPress writes into the
 * SQL and which ids come back, not what the callbacks leave in the query vars.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Products_Query_Exclusion extends WC_Unit_Test_Case {
	/**
	 * Original general settings.
	 *
	 * @var array|false
	 */
	private $original_settings;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_settings = get_option( 'woocommerce_pos_settings_general' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( false !== $this->original_settings ) {
			update_option( 'woocommerce_pos_settings_general', $this->original_settings );
		} else {
			delete_option( 'woocommerce_pos_settings_general' );
		}

		delete_option( 'woocommerce_pos_settings_visibility' );

		parent::tearDown();
	}

	/**
	 * A storefront product query carries the POS Only list once.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_storefront_query_product_writes_one_not_in(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( $pos_only );
		new Products();

		// Act.
		$query = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $query->posts ) );
		$this->assertSame( 1, substr_count( $query->request, 'NOT IN (' ), $query->request );
	}

	/**
	 * A query for products and variations hides both lists through one clause.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_storefront_query_products_and_variations_writes_one_not_in(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only_product   = ProductHelper::create_simple_product()->get_id();
		$variable_product   = ProductHelper::create_variation_product();
		$variation_ids      = $variable_product->get_children();
		$pos_only_variation = (int) $variation_ids[0];
		$this->set_pos_only_ids( array( $pos_only_product ), array( $pos_only_variation ) );
		new Products();

		// Act.
		$query = new WP_Query( $this->product_query_args( array( 'post_type' => array( 'product', 'product_variation' ) ) ) );
		$ids   = array_map( 'intval', $query->posts );

		// Assert.
		$this->assertNotContains( $pos_only_product, $ids );
		$this->assertNotContains( $pos_only_variation, $ids );
		$this->assertContains( $variable_product->get_id(), $ids );
		$this->assertSame( 1, substr_count( $query->request, 'NOT IN (' ), $query->request );
	}

	/**
	 * A get_posts() caller suppresses posts_where, so pre_get_posts must hide the list.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products
	 */
	public function test_get_posts_suppressed_filters_excludes_pos_only_ids(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();

		// Act.
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $ids ) );
	}

	/**
	 * A later pre_get_posts callback that suppresses filters still gets the exclusion.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products
	 */
	public function test_late_suppress_filters_callback_excludes_pos_only_ids(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();
		add_action(
			'pre_get_posts',
			function ( WP_Query $query ) {
				$query->set( 'suppress_filters', true );
			},
			20
		);

		// Act.
		$query = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $query->posts ) );
	}

	/**
	 * A later pre_get_posts callback that sets post__in cannot bring a POS Only id back.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_late_post_in_callback_excludes_pos_only_ids(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();
		add_action(
			'pre_get_posts',
			function ( WP_Query $query ) use ( $pos_only, $visible ) {
				$query->set( 'post__in', array( $pos_only, $visible ) );
			},
			20
		);

		// Act.
		$query = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $query->posts ) );
	}

	/**
	 * A later pre_get_posts callback that empties post__not_in cannot bring a POS Only id back.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_late_post_not_in_reset_callback_excludes_pos_only_ids(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();
		add_action(
			'pre_get_posts',
			function ( WP_Query $query ) {
				$query->set( 'post__not_in', array() );
			},
			20
		);

		// Act.
		$query = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $query->posts ) );
	}

	/**
	 * A later posts_where callback that rebuilds the id clauses cannot strip the exclusion.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_late_posts_where_callback_cannot_strip_exclusion(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();
		add_filter(
			'posts_where',
			function ( $where ) {
				return preg_replace( '/ AND \S+\.ID NOT IN \([^)]*\)/', '', $where );
			},
			20
		);

		// Act.
		$query = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $query->posts ) );
	}

	/**
	 * A query for one POS Only id by `p` returns nothing.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_query_by_p_pos_only_id_returns_nothing(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		ProductHelper::create_simple_product();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();

		// Act.
		$query = new WP_Query( $this->product_query_args( array( 'p' => $pos_only ) ) );

		// Assert.
		$this->assertSame( array(), $query->posts );
	}

	/**
	 * A settings write between two queries of one request shows in the second query.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_settings_change_mid_request_second_query_hides_new_id(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();
		$first = new WP_Query( $this->product_query_args() );

		// Act.
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => 'pos_only',
				'ids'        => array( $visible ),
			)
		);
		$second = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $first->posts ) );
		$this->assertSame( array(), $second->posts );
	}

	/**
	 * A settings filter that changes its answer between two queries is honoured.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_settings_filter_added_mid_request_second_query_hides_filtered_id(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$visible  = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		new Products();
		$first = new WP_Query( $this->product_query_args() );

		// Act.
		add_filter(
			'wcpos_pos_only_product_visibility_settings',
			function ( $settings ) use ( $visible ) {
				$settings['ids'] = array( $visible );
				return $settings;
			}
		);
		$second = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $visible ), array_map( 'intval', $first->posts ) );
		$this->assertSame( array( $pos_only ), array_map( 'intval', $second->posts ) );
	}

	/**
	 * A WooCommerce Analytics request is the merchant reporting, so nothing is excluded.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where
	 */
	public function test_wc_analytics_route_query_keeps_pos_only_products(): void {
		// Arrange.
		$this->enable_pos_only_products();
		$pos_only = ProductHelper::create_simple_product()->get_id();
		$this->set_pos_only_ids( array( $pos_only ) );
		$products = new Products();
		$request  = new WP_REST_Request( 'GET' );
		$request->set_route( '/wc-analytics/reports/products' );
		$products->record_rest_route( null, rest_get_server(), $request );

		// Act.
		$query = new WP_Query( $this->product_query_args() );

		// Assert.
		$this->assertSame( array( $pos_only ), array_map( 'intval', $query->posts ) );
		$this->assertSame( 0, substr_count( $query->request, 'NOT IN (' ), $query->request );
	}

	/**
	 * Arguments for a storefront product id query.
	 *
	 * @param array $args Arguments that override the defaults.
	 *
	 * @return array
	 */
	private function product_query_args( array $args = array() ): array {
		return array_merge(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			),
			$args
		);
	}

	/**
	 * Store POS Only product and variation id lists the way the settings screen does.
	 *
	 * @param int[] $product_ids   Product ids.
	 * @param int[] $variation_ids Variation ids.
	 */
	private function set_pos_only_ids( array $product_ids, array $variation_ids = array() ): void {
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products'   => array(
					'default' => array(
						'pos_only'    => array( 'ids' => $product_ids ),
						'online_only' => array( 'ids' => array() ),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => $variation_ids ),
						'online_only' => array( 'ids' => array() ),
					),
				),
			)
		);
	}

	/**
	 * Enable the POS Only products setting.
	 */
	private function enable_pos_only_products(): void {
		$settings                      = get_option( 'woocommerce_pos_settings_general', array() );
		$settings['pos_only_products'] = true;
		update_option( 'woocommerce_pos_settings_general', $settings );
	}
}
