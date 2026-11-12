<?php

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Install;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Query;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Products extends WP_UnitTestCase {
	public function setup(): void {
		parent::setup();

		WC_Install::create_pages();
		$shop_page_id = wc_get_page_id( 'shop' );
		$this->assertTrue(
			$shop_page_id && 'publish' == get_post_status( $shop_page_id ),
			'The WooCommerce "shop" page is not set up.'
		);
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_pos_only_products(): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new Products(); // reinstantiate the class to apply the filter

		// Create a visible product
		$visible_product = ProductHelper::create_simple_product();

		// Create a product with _pos_visibility set to 'pos_only'
		$hidden_product = ProductHelper::create_simple_product();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $hidden_product->get_id() ),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
			)
		);

		// Mimic the main WooCommerce query
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1, // Get all products for testing
		);

		$this->go_to( wc_get_page_id( 'shop' ) );

		$query = new WP_Query( $query_args );
		WC()->query->product_query( $query );
		$queried_ids = wp_list_pluck( $query->get_posts(), 'ID' );

		// Assert that the visible product is in the query
		$this->assertContains( $visible_product->get_id(), $queried_ids );

		// Assert that the hidden product is not in the query
		$this->assertNotContains( $hidden_product->get_id(), $queried_ids );
	}

	public function test_pos_only_variations(): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new Products(); // reinstantiate the class to apply the filter

		// create variations
		$product     = ProductHelper::create_variation_product();
		$variation_3 = new WC_Product_Variation();
		$variation_3->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => 'DUMMY SKU VARIABLE MEDIUM',
				'regular_price' => 10,
			)
		);
		$variation_3->set_attributes( array( 'pa_size' => 'medium' ) );
		$variation_3->save();

		$variation_ids = $product->get_children();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $variation_ids[0] ),
						),
						'online_only' => array(
							'ids' => array( $variation_ids[1] ),
						),
					),
				),
			)
		);

		// Mimic the main WooCommerce query for product variations
		$query_args = array(
			'post_type'      => 'product_variation',
			'post_status'    => 'publish',
			'posts_per_page' => -1, // Get all variations for testing
			'post_parent'    => $product->get_id(), // Ensure variations of the specific product are fetched
		);

		$this->go_to( wc_get_page_id( 'shop' ) );

		$query = new WP_Query( $query_args );
		WC()->query->product_query( $query );
		$queried_variation_ids = wp_list_pluck( $query->get_posts(), 'ID' );

		// Assert that the variation with '_pos_visibility' set to 'pos_only' is NOT in the query
		$this->assertNotContains( $variation_ids[0], $queried_variation_ids );

		// Assert that the variation with '_pos_visibility' set to 'online_only' IS in the query
		$this->assertContains( $variation_ids[1], $queried_variation_ids );

		// Assert that the variation without '_pos_visibility' set is in the query
		$this->assertContains( $variation_ids[2], $queried_variation_ids );
	}

	/**
	 * WordPress ignores post__not_in when post__in is set; the SQL guard must still exclude POS Only ids.
	 */
	public function test_pos_only_products_post_in_returns_only_visible_id(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$query = new WP_Query(
			array(
				'post_type' => 'product',
				'post__in'  => array( $visible->get_id(), $hidden->get_id() ),
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), $query->posts );
	}

	/**
	 * WooCommerce maps wc_get_products() include to WordPress post__in.
	 */
	public function test_pos_only_products_wc_include_returns_only_visible_id(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$ids = wc_get_products(
			array(
				'include' => array( $visible->get_id(), $hidden->get_id() ),
				'return'  => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), $ids );
	}

	/**
	 * WooCommerce related products, upsells and cross-sells use WC_Product::is_visible().
	 */
	public function test_pos_only_products_shop_visibility_hides_only_pos_only_product(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$hidden_visible = wc_get_product( $hidden->get_id() )->is_visible();
		$normal_visible = wc_get_product( $visible->get_id() )->is_visible();

		// Assert.
		$this->assertFalse( $hidden_visible );
		$this->assertTrue( $normal_visible );
	}

	/**
	 * WooCommerce variation is_visible() uses the product visibility hook, not variation_is_visible().
	 */
	public function test_pos_only_variations_shop_visibility_hides_only_pos_only_variation(): void {
		// Arrange.
		$product = ProductHelper::create_variation_product();
		$ids     = $product->get_children();
		$this->configure_storefront_visibility( array(), array( $ids[0] ) );

		// Act.
		$hidden_visible  = wc_get_product( $ids[0] )->is_visible();
		$sibling_visible = wc_get_product( $ids[1] )->is_visible();

		// Assert.
		$this->assertFalse( $hidden_visible );
		$this->assertTrue( $sibling_visible );
	}

	/**
	 * get_posts() sets suppress_filters, so posts_where never runs; the id list itself must be trimmed.
	 */
	public function test_pos_only_products_get_posts_include_returns_only_visible_id(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$ids = get_posts(
			array(
				'post_type' => 'product',
				'include'   => array( $visible->get_id(), $hidden->get_id() ),
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), $ids );
	}

	/**
	 * An id list made of POS Only products only must return nothing, not every product.
	 */
	public function test_pos_only_products_get_posts_include_all_hidden_returns_nothing(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$hidden = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );

		// Act.
		$ids = get_posts(
			array(
				'post_type' => 'product',
				'include'   => array( $hidden->get_id() ),
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array(), $ids );
	}

	/**
	 * The post__not_in clause WordPress writes already holds the POS Only ids; posts_where must not repeat them.
	 */
	public function test_pos_only_products_storefront_query_sql_lists_excluded_ids_once(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		$sql = '';
		add_filter(
			'posts_request',
			function ( $request ) use ( &$sql ) {
				$sql = $request;

				return $request;
			}
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		// Assert.
		$this->assertSame( 1, substr_count( $sql, '.ID NOT IN (' ) );
		$this->assertSame( array( $visible->get_id() ), $query->posts );
	}

	/**
	 * A caller's own post__not_in is merged with the POS Only ids into one clause.
	 */
	public function test_pos_only_products_caller_post_not_in_sql_lists_excluded_ids_once(): void {
		// Arrange.
		$visible_a = ProductHelper::create_simple_product();
		$visible_b = ProductHelper::create_simple_product();
		$hidden    = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );
		$sql = '';
		add_filter(
			'posts_request',
			function ( $request ) use ( &$sql ) {
				$sql = $request;

				return $request;
			}
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'post__not_in'   => array( $visible_b->get_id() ),
			)
		);

		// Assert.
		$this->assertSame( 1, substr_count( $sql, '.ID NOT IN (' ) );
		$this->assertSame( array( $visible_a->get_id() ), $query->posts );
	}

	/**
	 * WordPress ignores post__not_in when p is set, so posts_where excludes that one POS Only id, and only it.
	 */
	public function test_pos_only_products_single_id_query_for_pos_only_product_sql_excludes_only_that_id(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$hidden = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );

		// Act.
		$query = new WP_Query(
			array(
				'post_type' => 'product',
				'p'         => $hidden[1],
			)
		);

		// Assert.
		$this->assertSame( array(), $query->posts );
		$this->assertSame( 1, substr_count( $query->request, 'NOT IN (' ) );
		$this->assertStringContainsString( '.ID NOT IN (' . $hidden[1] . ')', $query->request );
		$this->assertDoesNotMatchRegularExpression( '/\b(' . $hidden[0] . '|' . $hidden[2] . ')\b/', $query->request );
	}

	/**
	 * A single-id query for a visible product needs no NOT IN clause at all.
	 */
	public function test_pos_only_products_single_id_query_for_visible_product_sql_has_no_not_in(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );

		// Act.
		$query = new WP_Query(
			array(
				'post_type' => 'product',
				'p'         => $visible->get_id(),
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), wp_list_pluck( $query->posts, 'ID' ) );
		$this->assertStringNotContainsString( 'NOT IN', $query->request );
	}

	/**
	 * A post__in set by a later pre_get_posts callback skips post__not_in; posts_where must still exclude.
	 */
	public function test_pos_only_products_post_in_set_after_exclusion_hook_still_excludes(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );
		add_action(
			'pre_get_posts',
			function ( $query ) use ( $visible, $hidden ) {
				if ( 'product' === $query->get( 'post_type' ) ) {
					$query->set( 'post__in', array( $visible->get_id(), $hidden->get_id() ) );
				}
			},
			20
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type' => 'product',
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array( $visible->get_id() ), $query->posts );
	}

	/**
	 * A post__not_in emptied or replaced by a later pre_get_posts callback must not leak POS Only ids.
	 *
	 * @dataProvider post_not_in_replacements
	 *
	 * @param bool $replace Whether the callback replaces post__not_in with another id rather than emptying it.
	 */
	public function test_pos_only_products_post_not_in_replaced_after_exclusion_hook_still_excludes( bool $replace ): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$other   = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $hidden->get_id() ) );
		add_action(
			'pre_get_posts',
			function ( $query ) use ( $replace, $other ) {
				if ( 'product' === $query->get( 'post_type' ) ) {
					$query->set( 'post__not_in', $replace ? array( $other->get_id() ) : array() );
				}
			},
			20
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		// Assert.
		$this->assertNotContains( $hidden->get_id(), $query->posts );
		$this->assertContains( $visible->get_id(), $query->posts );
	}

	/**
	 * Data provider: how a later pre_get_posts callback overrides post__not_in.
	 *
	 * @return array<string, array{bool}>
	 */
	public function post_not_in_replacements(): array {
		return array(
			'emptied'  => array( false ),
			'replaced' => array( true ),
		);
	}

	/**
	 * A later post__not_in of negative id strings is read as WordPress reads it, so each POS Only id is written once.
	 */
	public function test_pos_only_products_third_party_negative_post__not_in_sql_lists_each_pos_only_id_once(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$hidden  = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		add_action(
			'pre_get_posts',
			function ( $query ) use ( $hidden ) {
				if ( 'product' === $query->get( 'post_type' ) ) {
					$query->set(
						'post__not_in',
						array_map(
							static function ( $id ) {
								return '-' . $id;
							},
							$hidden
						)
					);
				}
			},
			20
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		// Assert.
		foreach ( $hidden as $id ) {
			$this->assertSame( 1, preg_match_all( '/\b' . $id . '\b/', $query->request ), 'POS Only id ' . $id );
		}
		$this->assertSame( array( $visible->get_id() ), $query->posts );
	}

	/**
	 * WordPress sorts post__not_in, so an unsorted POS Only list misses the shortcut; each id is still written once.
	 */
	public function test_pos_only_products_unsorted_list_sql_lists_each_pos_only_id_once(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product();
		$created = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$hidden  = array( $created[2], $created[0], $created[1] );
		$this->configure_storefront_visibility( $hidden );
		Settings::instance()->reset_sections_for_testing();
		$this->assertSame( $hidden, array_map( 'intval', Settings::instance()->get_pos_only_product_visibility_settings()['ids'] ), 'precondition: stored out of ascending order' );

		// Act.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		// Assert.
		foreach ( $hidden as $id ) {
			$this->assertSame( 1, preg_match_all( '/\b' . $id . '\b/', $query->request ), 'POS Only id ' . $id );
		}
		$this->assertSame( array( $visible->get_id() ), $query->posts );
	}

	/**
	 * MySQL reads a hex p as an id while absint reads it as 0, so the full POS Only list stays in the SQL.
	 */
	public function test_pos_only_products_third_party_hex_p_keeps_the_full_list(): void {
		// Arrange.
		$hidden = ProductHelper::create_simple_product()->get_id();
		$this->configure_storefront_visibility( array( $hidden ) );
		$query = new WP_Query();
		add_action(
			'pre_get_posts',
			function ( $candidate ) use ( $query, $hidden ) {
				if ( $candidate === $query ) {
					$candidate->set( 'p', sprintf( '0x%X', $hidden ) );
				}
			},
			20
		);

		// Act.
		$posts = $query->query(
			array(
				'post_type' => 'product',
				'fields'    => 'ids',
			)
		);

		// Assert.
		$this->assertSame( array(), $posts );
		$this->assertMatchesRegularExpression( '/NOT IN \(' . $hidden . '\)/', $query->request );
	}

	/**
	 * An earlier posts_where callback that zeroes p leaves WordPress's ID = p clause, so the full POS Only list stays.
	 */
	public function test_pos_only_products_earlier_posts_where_zeroing_p_keeps_the_full_list(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$hidden = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		add_filter(
			'posts_where',
			function ( $where, $query ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$query->set( 'p', 0 );
				}

				return $where;
			},
			5,
			2
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'         => 'product',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'p'                 => $hidden[1],
				'wcpos_test_tamper' => true,
			)
		);

		// Assert.
		$this->assertNotContains( $hidden[1], $query->posts );
		$this->assert_sql_appends_every_pos_only_id( $hidden, $query->request );
	}

	/**
	 * An earlier posts_where callback that points p at a visible id leaves ID = p in the SQL, so the full list stays.
	 */
	public function test_pos_only_products_earlier_posts_where_changing_p_keeps_the_full_list(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product()->get_id();
		$hidden  = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		add_filter(
			'posts_where',
			function ( $where, $query ) use ( $visible ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$query->set( 'p', $visible );
				}

				return $where;
			},
			5,
			2
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'         => 'product',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'p'                 => $hidden[1],
				'wcpos_test_tamper' => true,
			)
		);

		// Assert.
		$this->assertNotContains( $hidden[1], $query->posts );
		$this->assert_sql_appends_every_pos_only_id( $hidden, $query->request );
	}

	/**
	 * A p changed to the digit prefix of WordPress's id must not match inside its ID = p clause, so the full list stays.
	 */
	public function test_pos_only_products_earlier_posts_where_changing_p_to_its_digit_prefix_keeps_the_full_list(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$hidden = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$prefix = intdiv( $hidden[1], 10 );
		$this->assertGreaterThanOrEqual( 10, $hidden[1], 'precondition: the id has a digit prefix' );
		$this->assertNotContains( $prefix, $hidden, 'precondition: the prefix is not a POS Only id' );
		$this->configure_storefront_visibility( $hidden );
		add_filter(
			'posts_where',
			function ( $where, $query ) use ( $prefix ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$query->set( 'p', $prefix );
				}

				return $where;
			},
			5,
			2
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'         => 'product',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'p'                 => $hidden[1],
				'wcpos_test_tamper' => true,
			)
		);

		// Assert.
		$this->assertNotContains( $hidden[1], $query->posts );
		$this->assert_sql_appends_every_pos_only_id( $hidden, $query->request );
	}

	/**
	 * An earlier posts_where callback that clears post__in leaves WordPress's ID IN clause, so the full list stays.
	 */
	public function test_pos_only_products_earlier_posts_where_clearing_post__in_keeps_the_full_list(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$hidden = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		add_filter(
			'posts_where',
			function ( $where, $query ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$query->set( 'post__in', array() );
				}

				return $where;
			},
			5,
			2
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'         => 'product',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'post__in'          => array( '-' . $hidden[1] ),
				'wcpos_test_tamper' => true,
			)
		);

		// Assert.
		$this->assertNotContains( $hidden[1], $query->posts );
		$this->assert_sql_appends_every_pos_only_id( $hidden, $query->request );
	}

	/**
	 * An earlier posts_where callback that replaces post__in leaves WordPress's ID IN clause, so the full list stays.
	 */
	public function test_pos_only_products_earlier_posts_where_replacing_post__in_keeps_the_full_list(): void {
		// Arrange.
		$visible = ProductHelper::create_simple_product()->get_id();
		$hidden  = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		add_filter(
			'posts_where',
			function ( $where, $query ) use ( $visible ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$query->set( 'post__in', array( $visible ) );
				}

				return $where;
			},
			5,
			2
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'         => 'product',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'post__in'          => array( '-' . $hidden[1] ),
				'wcpos_test_tamper' => true,
			)
		);

		// Assert.
		$this->assertNotContains( $hidden[1], $query->posts );
		$this->assert_sql_appends_every_pos_only_id( $hidden, $query->request );
	}

	/**
	 * An earlier posts_where callback that strips WordPress's ID NOT IN clause leaves post__not_in set; the full list stays.
	 */
	public function test_pos_only_products_earlier_posts_where_removing_the_not_in_clause_keeps_the_full_list(): void {
		global $wpdb;

		// Arrange.
		ProductHelper::create_simple_product();
		$hidden = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		$removed = 0;
		add_filter(
			'posts_where',
			function ( $where, $query ) use ( $wpdb, &$removed ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$where = preg_replace( '/ AND ' . preg_quote( $wpdb->posts, '/' ) . '\.ID NOT IN \([0-9,]+\)/', '', $where, 1, $removed );
				}

				return $where;
			},
			5,
			2
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'         => 'product',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'wcpos_test_tamper' => true,
			)
		);

		// Assert.
		$this->assertSame( 1, $removed, 'precondition: WordPress wrote one ID NOT IN clause' );
		foreach ( $hidden as $id ) {
			$this->assertNotContains( $id, $query->posts );
		}
		$this->assert_sql_appends_every_pos_only_id( $hidden, $query->request );
	}

	/**
	 * An earlier posts_where callback that rewrites post__not_in no longer matches WordPress's clause; the full list stays.
	 *
	 * @dataProvider post_not_in_tamper_values
	 *
	 * @param string $tamper Which value the callback writes into post__not_in.
	 */
	public function test_pos_only_products_earlier_posts_where_replacing_post__not_in_keeps_the_full_list( string $tamper ): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$other  = ProductHelper::create_simple_product()->get_id();
		$hidden = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->configure_storefront_visibility( $hidden );
		$value = 'stored' === $tamper ? $hidden : array_merge( array_reverse( $hidden ), array( $other ) );
		add_action(
			'pre_get_posts',
			function ( $query ) use ( $other ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$query->set( 'post__not_in', array( $other ) );
				}
			},
			20
		);
		add_filter(
			'posts_where',
			function ( $where, $query ) use ( $value ) {
				if ( $query->get( 'wcpos_test_tamper' ) ) {
					$query->set( 'post__not_in', $value );
				}

				return $where;
			},
			5,
			2
		);

		// Act.
		$query = new WP_Query(
			array(
				'post_type'         => 'product',
				'fields'            => 'ids',
				'posts_per_page'    => -1,
				'wcpos_test_tamper' => true,
			)
		);

		// Assert.
		foreach ( $hidden as $id ) {
			$this->assertNotContains( $id, $query->posts );
		}
		$this->assert_sql_appends_every_pos_only_id( $hidden, $query->request );
	}

	/**
	 * Data provider: what an earlier posts_where callback writes into post__not_in.
	 *
	 * @return array<string, array{string}>
	 */
	public function post_not_in_tamper_values(): array {
		return array(
			'the stored POS Only list'                  => array( 'stored' ),
			'the stored list reversed, plus another id' => array( 'reversed_plus_other' ),
		);
	}

	/**
	 * WordPress writes post__in de-duplicated and sorted, so an unsorted duplicate list still finds its clause.
	 */
	public function test_pos_only_products_unsorted_duplicate_post__in_sql_has_no_not_in(): void {
		// Arrange.
		$v1     = ProductHelper::create_simple_product()->get_id();
		$v2     = ProductHelper::create_simple_product()->get_id();
		$hidden = array(
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
			ProductHelper::create_simple_product()->get_id(),
		);
		$this->assertGreaterThan( $v1, $v2, 'precondition: v2 sorts after v1' );
		$this->configure_storefront_visibility( $hidden );

		// Act.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'post__in'       => array( $v2, $v1, $v2 ),
			)
		);

		// Assert.
		$posts = $query->posts;
		sort( $posts );
		$this->assertSame( array( $v1, $v2 ), $posts );
		$this->assertStringNotContainsString( 'NOT IN', $query->request );
	}

	/**
	 * A visibility settings write between two queries in one request applies to the second query.
	 */
	public function test_pos_only_products_visibility_change_mid_request_applies_to_next_query(): void {
		// Arrange.
		$product_a = ProductHelper::create_simple_product();
		$product_b = ProductHelper::create_simple_product();
		$this->configure_storefront_visibility( array( $product_a->get_id() ) );
		$args = array(
			'post_type'      => 'product',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		);

		// Act.
		$first = new WP_Query( $args );
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products'   => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array( $product_b->get_id() ) ),
						'online_only' => array( 'ids' => array() ),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array() ),
						'online_only' => array( 'ids' => array() ),
					),
				),
			)
		);
		$second = new WP_Query( $args );

		// Assert.
		$this->assertNotContains( $product_a->get_id(), $first->posts );
		$this->assertContains( $product_b->get_id(), $first->posts );
		$this->assertContains( $product_a->get_id(), $second->posts );
		$this->assertNotContains( $product_b->get_id(), $second->posts );
	}

	/**
	 * Assert the last ID NOT IN clause in the SQL (the one posts_where appends) lists every POS Only id.
	 *
	 * @param int[]  $pos_only_ids The stored POS Only ids.
	 * @param string $sql          The query's SQL.
	 */
	private function assert_sql_appends_every_pos_only_id( array $pos_only_ids, string $sql ): void {
		$this->assertGreaterThanOrEqual( 1, preg_match_all( '/\.ID NOT IN \(([0-9,]+)\)/', $sql, $matches ), 'no ID NOT IN clause in the SQL' );
		$appended = array_map( 'intval', explode( ',', end( $matches[1] ) ) );
		sort( $appended );
		sort( $pos_only_ids );
		$this->assertSame( $pos_only_ids, $appended );
	}

	/**
	 * Enable the registered storefront hooks and store visibility settings for these fixtures.
	 */
	private function configure_storefront_visibility( array $product_ids, array $variation_ids = array() ): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new Products();
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
		$this->go_to( wc_get_page_id( 'shop' ) );
	}
}
