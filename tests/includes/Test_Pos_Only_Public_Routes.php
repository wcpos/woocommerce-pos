<?php
/**
 * Public WordPress routes for POS Only products and their reviews.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Install;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shoppers cannot distinguish POS Only products from missing items on public routes.
 *
 * @covers \WCPOS\WooCommercePOS\Products
 */
class Test_Pos_Only_Public_Routes extends WCPOS_REST_Unit_Test_Case {
	/**
	 * POS Only product id.
	 *
	 * @var int
	 */
	private $pos_only;

	/**
	 * Visible product id.
	 *
	 * @var int
	 */
	private $visible;

	/**
	 * Product category id shared by both products.
	 *
	 * @var int
	 */
	private $term_id;

	/**
	 * Published blog post id.
	 *
	 * @var int
	 */
	private $blog_post;

	/**
	 * Approved comment id on the blog post.
	 *
	 * @var int
	 */
	private $blog_comment;

	/**
	 * Review ids by product fixture.
	 *
	 * @var array<string, int>
	 */
	private $reviews = array();

	/**
	 * User ids by role.
	 *
	 * @var array<string, int>
	 */
	private $users = array();

	/**
	 * Create a POS Only product and a visible control, each with one approved review.
	 */
	public function setUp(): void {
		parent::setUp();
		Settings::instance()->reset_sections_for_testing();
		delete_option( Pos_Visibility::OPTION );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		new Products();

		$term           = wp_insert_term( 'Public Routes Category', 'product_cat' );
		$this->term_id  = (int) $term['term_id'];
		$this->pos_only = ProductHelper::create_simple_product(
			array(
				'name'         => 'Public Routes POS Only Alpha',
				'slug'         => 'public-routes-pos-only-alpha',
				'sku'          => 'PR-POS-ONLY',
				'category_ids' => array( $this->term_id ),
			)
		)->get_id();
		$this->visible  = ProductHelper::create_simple_product(
			array(
				'name'         => 'Public Routes Visible Bravo',
				'slug'         => 'public-routes-visible-bravo',
				'sku'          => 'PR-VISIBLE',
				'category_ids' => array( $this->term_id ),
			)
		)->get_id();

		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => 'pos_only',
				'ids'        => array( $this->pos_only ),
				'scope'      => 'default',
			)
		);

		$products = array(
			'pos_only' => $this->pos_only,
			'visible'  => $this->visible,
		);
		foreach ( $products as $key => $product_id ) {
			$id = wp_insert_comment(
				array(
					'comment_post_ID'  => $product_id,
					'comment_type'     => 'review',
					'comment_approved' => 1,
					'comment_author'   => 'Reviewer',
					'comment_content'  => 'Review of ' . get_the_title( $product_id ),
				)
			);
			update_comment_meta( $id, 'rating', 5 );
			$this->reviews[ $key ] = $id;
		}

		$this->blog_post    = self::factory()->post->create( array( 'post_title' => 'Public Routes Blog Post' ) );
		$this->blog_comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->blog_post,
				'comment_approved' => 1,
			)
		);

		$this->reviews['pos_only_comment'] = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->pos_only,
				'comment_approved' => 1,
				'comment_content'  => 'Comment on Public Routes POS Only Alpha',
			)
		);

		foreach ( array( 'shop_manager', 'customer' ) as $role ) {
			$this->users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}

		WC_Install::create_pages();
		$this->go_to( wc_get_page_id( 'shop' ) );
		wp_set_current_user( 0 );

		// A REST request starts with no global post. The fixtures leave one set, and oEmbed
		// falls back to it for a URL that resolves to no post.
		$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset test state to a fresh request.
	}

	/**
	 * Remove the visibility settings and restore plain permalinks.
	 */
	public function tearDown(): void {
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		Settings::instance()->reset_sections_for_testing();
		$this->set_permalink_structure( '' );
		parent::tearDown();
	}

	/**
	 * A hidden product has the same error as a missing product, with no title in the body.
	 */
	public function test_wp_v2_product_single_pos_only_returns_same_404_as_missing_product(): void {
		$response = $this->get( '/wp/v2/product/' . $this->pos_only );
		$this->assert_same_error( $this->get( '/wp/v2/product/999999' ), $response );
		$this->assertStringNotContainsString( 'Public Routes POS Only Alpha', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Asking for edit context does not reveal a hidden product through a permissions error.
	 */
	public function test_wp_v2_product_single_pos_only_in_edit_context_anonymous_returns_same_404_as_missing_product(): void {
		$params = array( 'context' => 'edit' );
		$this->assert_same_error( $this->get( '/wp/v2/product/999999', $params ), $this->get( '/wp/v2/product/' . $this->pos_only, $params ) );
	}

	/**
	 * A signed-in customer still gets a missing-product error.
	 */
	public function test_wp_v2_product_single_pos_only_for_customer_returns_404(): void {
		wp_set_current_user( $this->users['customer'] );
		$response = $this->get( '/wp/v2/product/' . $this->pos_only );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_post_invalid_id', $response->get_data()['code'] );
	}

	/**
	 * A manager can load the product for editing.
	 */
	public function test_wp_v2_product_single_pos_only_for_shop_manager_in_edit_context_returns_200(): void {
		wp_set_current_user( $this->users['shop_manager'] );
		$response = $this->get( '/wp/v2/product/' . $this->pos_only, array( 'context' => 'edit' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->pos_only, $response->get_data()['id'] );
	}

	/**
	 * A POS request can still read the hidden product.
	 */
	public function test_wp_v2_product_single_pos_only_on_pos_request_returns_200(): void {
		$response = $this->as_pos(
			function () {
				return $this->get( '/wp/v2/product/' . $this->pos_only );
			}
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->pos_only, $response->get_data()['id'] );
	}

	/**
	 * Turning the setting off restores the public product response.
	 */
	public function test_wp_v2_product_single_with_pos_only_setting_off_returns_200(): void {
		$this->set_pos_only_flag_on_next_request( false );
		$response = $this->get( '/wp/v2/product/' . $this->pos_only );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->pos_only, $response->get_data()['id'] );
	}

	/**
	 * Core never prepares a hidden product, and after-callback filters already see the error.
	 */
	public function test_wp_v2_product_single_pos_only_never_builds_product_body(): void {
		$calls     = 0;
		$saw_error = false;
		$prepare   = static function ( $response ) use ( &$calls ) {
			++$calls;
			return $response;
		};
		$after     = static function ( $response ) use ( &$saw_error ) {
			$saw_error = $response instanceof WP_Error;
			return $response;
		};
		add_filter( 'rest_prepare_product', $prepare );
		add_filter( 'rest_request_after_callbacks', $after, 0 );
		try {
			$this->get( '/wp/v2/product/' . $this->pos_only );
		} finally {
			remove_filter( 'rest_prepare_product', $prepare );
			remove_filter( 'rest_request_after_callbacks', $after, 0 );
		}
		$this->assertSame( 0, $calls );
		$this->assertTrue( $saw_error );
	}

	/**
	 * A shopper still reads the visible product.
	 */
	public function test_wp_v2_product_single_visible_product_returns_200(): void {
		$response = $this->get( '/wp/v2/product/' . $this->visible );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->visible, $response->get_data()['id'] );
	}

	/**
	 * Neither the product list nor its total reveals a matching hidden product.
	 *
	 * @dataProvider wp_v2_product_collection_cases
	 *
	 * @param string $field The collection query field.
	 * @param mixed  $value The query value, or null for the fixture id.
	 */
	public function test_wp_v2_product_collection_pos_only_query_returns_no_item_and_zero_total( string $field, $value ): void {
		$response = $this->get( '/wp/v2/product', array( $field => $value ?? $this->pos_only ) );
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( 0, (int) $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * Product collection lookups that target only the hidden product.
	 */
	public function wp_v2_product_collection_cases(): array {
		return array(
			'include' => array( 'include', null ),
			'search'  => array( 'search', 'POS Only Alpha' ),
			'slug'    => array( 'slug', 'public-routes-pos-only-alpha' ),
		);
	}

	/**
	 * WordPress search never lists the hidden product id.
	 *
	 * @dataProvider wp_v2_search_cases
	 *
	 * @param array $params The search parameters, with a placeholder for the fixture id.
	 */
	public function test_wp_v2_search_pos_only_product_with_or_without_subtype_returns_no_item( array $params ): void {
		if ( isset( $params['include'] ) ) {
			$params['include'] = $this->pos_only;
		}
		$response = $this->get( '/wp/v2/search', $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( $this->pos_only, wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * Search by title with and without a subtype, and by id with the product subtype.
	 */
	public function wp_v2_search_cases(): array {
		return array(
			'product search' => array(
				array(
					'search'  => 'Public Routes POS Only Alpha',
					'subtype' => 'product',
				),
			),
			'all subtypes'   => array( array( 'search' => 'Public Routes POS Only Alpha' ) ),
			'include'        => array(
				array(
					'include' => true,
					'subtype' => 'product',
				),
			),
		);
	}

	/**
	 * Embedding a hidden product by id gets the same error as embedding a missing post.
	 *
	 * @dataProvider oembed_p_query_cases
	 *
	 * @param string $path   URL path template for the product id.
	 * @param array  $params Additional oEmbed parameters.
	 */
	public function test_oembed_p_query_url_of_pos_only_product_returns_same_404_as_missing_post( string $path, array $params ): void {
		$params['url'] = home_url( sprintf( $path, 999999 ) );
		$missing       = $this->get( '/oembed/1.0/embed', $params );
		$params['url'] = home_url( sprintf( $path, $this->pos_only ) );
		$this->assert_same_error( $missing, $this->get( '/oembed/1.0/embed', $params ) );
	}

	/**
	 * Plain-permalink URLs and formats that resolve a product by numeric id.
	 */
	public function oembed_p_query_cases(): array {
		return array(
			'id'        => array( '/?p=%d', array() ),
			'post type' => array( '/?post_type=product&p=%d', array() ),
			'xml'       => array( '/?p=%d', array( 'format' => 'xml' ) ),
		);
	}

	/**
	 * Pretty permalinks do not expose a hidden product through an id-based embed URL.
	 */
	public function test_oembed_p_query_url_with_pretty_permalinks_of_pos_only_product_returns_404(): void {
		$this->pretty_permalinks();
		$response = $this->get( '/oembed/1.0/embed', array( 'url' => home_url( '/?p=' . $this->pos_only ) ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'oembed_invalid_url', $response->get_data()['code'] );
	}

	/**
	 * The hidden product's pretty permalink is not a valid public embed URL either.
	 */
	public function test_oembed_permalink_of_pos_only_product_returns_404(): void {
		$this->pretty_permalinks();
		$response = $this->get( '/oembed/1.0/embed', array( 'url' => get_permalink( $this->pos_only ) ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'oembed_invalid_url', $response->get_data()['code'] );
	}

	/**
	 * Shoppers can still embed the visible product by id.
	 */
	public function test_oembed_p_query_url_of_visible_product_returns_200(): void {
		$response = $this->get( '/oembed/1.0/embed', array( 'url' => home_url( '/?p=' . $this->visible ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Public Routes Visible Bravo', $response->get_data()['title'] );
	}

	/**
	 * A manager can load embed data for a hidden product on either editor route.
	 *
	 * @dataProvider shop_manager_oembed_routes
	 *
	 * @param string $route The oEmbed route.
	 */
	public function test_oembed_p_query_url_of_pos_only_product_for_shop_manager_returns_200( string $route ): void {
		wp_set_current_user( $this->users['shop_manager'] );
		$response = $this->get( $route, array( 'url' => home_url( '/?p=' . $this->pos_only ) ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Direct and proxied embed routes used for previews.
	 */
	public function shop_manager_oembed_routes(): array {
		return array(
			'embed' => array( '/oembed/1.0/embed' ),
			'proxy' => array( '/oembed/1.0/proxy' ),
		);
	}

	/**
	 * A hidden product's id produces a 404 without redirecting to its slug.
	 *
	 * @dataProvider canonical_p_query_cases
	 *
	 * @param string $path URL path template for the product id.
	 */
	public function test_canonical_redirect_of_p_query_for_pos_only_product_returns_no_redirect( string $path ): void {
		$this->pretty_permalinks();
		$url = home_url( sprintf( $path, $this->pos_only ) );
		$this->go_to( $url );
		$this->assertTrue( is_404() );
		$this->assertNull( redirect_canonical( $url, false ) );
	}

	/**
	 * Product-id queries for the product page and the embed page.
	 */
	public function canonical_p_query_cases(): array {
		return array(
			'product' => array( '/?p=%d' ),
			'embed'   => array( '/?p=%d&embed=true' ),
		);
	}

	/**
	 * A visible product's id still redirects to its pretty permalink.
	 */
	public function test_canonical_redirect_of_p_query_for_visible_product_returns_permalink(): void {
		$this->pretty_permalinks();
		$url = home_url( '/?p=' . $this->visible );
		$this->go_to( $url );
		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $this->visible ), redirect_canonical( $url, false ) );
	}

	/**
	 * Neither permalink structure exposes the hidden product's embed page.
	 *
	 * @dataProvider embed_page_cases
	 *
	 * @param bool $pretty Whether to use pretty permalinks.
	 */
	public function test_embed_page_of_pos_only_product_is_404( bool $pretty ): void {
		if ( $pretty ) {
			$this->pretty_permalinks();
			$url = trailingslashit( get_permalink( $this->pos_only ) ) . 'embed/';
		} else {
			$url = home_url( '/?product=public-routes-pos-only-alpha&embed=true' );
		}
		$this->go_to( $url );
		$this->assertTrue( is_404() );
	}

	/**
	 * Pretty and plain embed page URLs.
	 */
	public function embed_page_cases(): array {
		return array(
			'pretty' => array( true ),
			'plain'  => array( false ),
		);
	}

	/**
	 * The review of a hidden product looks just like a missing comment.
	 */
	public function test_wp_v2_comment_single_review_of_pos_only_product_returns_same_404_as_missing_comment(): void {
		$this->assert_same_error( $this->get( '/wp/v2/comments/999999' ), $this->get( '/wp/v2/comments/' . $this->reviews['pos_only'] ) );
	}

	/**
	 * A manager can read the review of the hidden product.
	 */
	public function test_wp_v2_comment_single_review_of_pos_only_product_for_shop_manager_returns_200(): void {
		wp_set_current_user( $this->users['shop_manager'] );
		$response = $this->get( '/wp/v2/comments/' . $this->reviews['pos_only'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->reviews['pos_only'], $response->get_data()['id'] );
	}

	/**
	 * A shopper can read the review of the visible product.
	 */
	public function test_wp_v2_comment_single_review_of_visible_product_returns_200(): void {
		$response = $this->get( '/wp/v2/comments/' . $this->reviews['visible'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->reviews['visible'], $response->get_data()['id'] );
	}

	/** The shopper's review list and both totals describe only the visible review. */
	public function test_store_api_reviews_list_omits_pos_only_product_review_and_counts(): void {
		$response = $this->get( '/wc/store/v1/products/reviews' );
		$this->assertSame( array( $this->reviews['visible'] ), $this->review_ids( $response ) );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-TotalPages'] );
		$this->assertStringNotContainsString( 'Public Routes POS Only Alpha', wp_json_encode( $response->get_data() ) );
	}

	/** A one-review page leaves no second page for the hidden review. */
	public function test_store_api_reviews_paged_list_counts_exclude_pos_only_review(): void {
		$params   = array( 'per_page' => 1 );
		$response = $this->get( '/wc/store/v1/products/reviews', $params );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-TotalPages'] );

		$params['page'] = 2;
		$response       = $this->get( '/wc/store/v1/products/reviews', $params );
		$this->assertSame( array(), $response->get_data() );
	}

	/** The separate count query for an empty page still excludes the hidden review. */
	public function test_store_api_reviews_out_of_range_page_total_excludes_pos_only_review(): void {
		$response = $this->get(
			'/wc/store/v1/products/reviews',
			array(
				'per_page' => 1,
				'page'     => 5,
			)
		);
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-Total'] );
	}

	/** Filtering reviews by a hidden product is indistinguishable from filtering by a missing one. */
	public function test_store_api_reviews_product_id_of_pos_only_product_matches_missing_product(): void {
		$hidden  = $this->get( '/wc/store/v1/products/reviews', array( 'product_id' => (string) $this->pos_only ) );
		$missing = $this->get( '/wc/store/v1/products/reviews', array( 'product_id' => '999999' ) );
		$this->assertSame( 200, $hidden->get_status() );
		$this->assertSame( 200, $missing->get_status() );
		$this->assertSame( $missing->get_data(), $hidden->get_data() );
		$this->assertSame( $missing->get_headers()['X-WP-Total'], $hidden->get_headers()['X-WP-Total'] );
		$this->assertSame( $missing->get_headers()['X-WP-TotalPages'], $hidden->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( array(), $hidden->get_data() );
	}

	/** A mixed list of product ids returns only the visible product's review. */
	public function test_store_api_reviews_product_ids_mixed_returns_only_visible_review(): void {
		$response = $this->get( '/wc/store/v1/products/reviews', array( 'product_id' => $this->pos_only . ',' . $this->visible ) );
		$this->assertSame( array( $this->reviews['visible'] ), $this->review_ids( $response ) );
	}

	/** The shared category returns only the visible product's review. */
	public function test_store_api_reviews_category_id_omits_pos_only_product_review(): void {
		$response = $this->get( '/wc/store/v1/products/reviews', array( 'category_id' => (string) $this->term_id ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->reviews['visible'] ), $this->review_ids( $response ) );
	}

	/** A shop manager can list both product reviews. */
	public function test_store_api_reviews_for_shop_manager_include_pos_only_review(): void {
		wp_set_current_user( $this->users['shop_manager'] );
		$response = $this->get( '/wc/store/v1/products/reviews' );
		$ids      = $this->review_ids( $response );
		$this->assertContains( $this->reviews['pos_only'], $ids );
		$this->assertContains( $this->reviews['visible'], $ids );
		$this->assertSame( 2, (int) $response->get_headers()['X-WP-Total'] );
	}

	/** A POS request can list both product reviews. */
	public function test_store_api_reviews_on_pos_request_include_pos_only_review(): void {
		$response = $this->as_pos(
			function () {
				return $this->get( '/wc/store/v1/products/reviews' );
			}
		);
		$ids      = $this->review_ids( $response );
		$this->assertContains( $this->reviews['pos_only'], $ids );
		$this->assertContains( $this->reviews['visible'], $ids );
		$this->assertSame( 2, (int) $response->get_headers()['X-WP-Total'] );
	}

	/** A shopper's review list after a POS request still omits the hidden review. */
	public function test_store_api_reviews_after_pos_request_still_omit_pos_only_review(): void {
		$get_reviews = function () {
			return $this->get( '/wc/store/v1/products/reviews' );
		};
		$pos         = $this->as_pos( $get_reviews );
		$ids         = $this->review_ids( $pos );
		$this->assertContains( $this->reviews['pos_only'], $ids );
		$this->assertContains( $this->reviews['visible'], $ids );
		$this->assertSame( 2, (int) $pos->get_headers()['X-WP-Total'] );

		$shopper = $get_reviews();
		$this->assertSame( array( $this->reviews['visible'] ), $this->review_ids( $shopper ) );
		$this->assertSame( 1, (int) $shopper->get_headers()['X-WP-Total'] );
	}

	/** A shopper's comment query does not reuse the cached result of the same query made for the POS. */
	public function test_comment_query_after_pos_query_does_not_reuse_pos_cache(): void {
		$review_ids = function () {
			$comments = get_comments(
				array(
					'post_type' => 'product',
					'status'    => 'approve',
				)
			);
			return array_map( 'intval', wp_list_pluck( $comments, 'comment_ID' ) );
		};

		$pos = $this->as_pos( $review_ids );
		$this->assertContains( $this->reviews['pos_only'], $pos );
		$this->assertContains( $this->reviews['visible'], $pos );

		$shopper = $review_ids();
		$this->assertNotContains( $this->reviews['pos_only'], $shopper );
		$this->assertNotContains( $this->reviews['pos_only_comment'], $shopper );
		$this->assertContains( $this->reviews['visible'], $shopper );
	}

	/** Disabling POS Only products restores both reviews to the shopper's list. */
	public function test_store_api_reviews_with_pos_only_setting_off_include_pos_only_review(): void {
		$this->set_pos_only_flag_on_next_request( false );
		$response = $this->get( '/wc/store/v1/products/reviews' );
		$ids      = $this->review_ids( $response );
		$this->assertContains( $this->reviews['pos_only'], $ids );
		$this->assertContains( $this->reviews['visible'], $ids );
		$this->assertSame( 2, (int) $response->get_headers()['X-WP-Total'] );
	}

	/** The public comments collection includes the blog comment but not the hidden product's comment. */
	public function test_wp_v2_comments_list_omits_comment_on_pos_only_product(): void {
		$response = $this->get( '/wp/v2/comments' );
		$ids      = $this->review_ids( $response );
		$this->assertNotContains( $this->reviews['pos_only_comment'], $ids );
		$this->assertContains( $this->blog_comment, $ids );
		$this->assertSame( count( $response->get_data() ), (int) $response->get_headers()['X-WP-Total'] );
	}

	/** A shop manager can list the regular comment on a hidden product. */
	public function test_wp_v2_comments_list_for_shop_manager_includes_comment_on_pos_only_product(): void {
		wp_set_current_user( $this->users['shop_manager'] );
		$response = $this->get( '/wp/v2/comments' );
		$this->assertContains( $this->reviews['pos_only_comment'], $this->review_ids( $response ) );
	}

	/** The comments feed omits both kinds of comment on the hidden product. */
	public function test_comments_feed_omits_pos_only_product_review(): void {
		$this->go_to( home_url( '/?feed=comments-rss2' ) );
		$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->comments, 'comment_ID' ) );
		$this->assertNotContains( $this->reviews['pos_only'], $ids );
		$this->assertNotContains( $this->reviews['pos_only_comment'], $ids );
		$this->assertContains( $this->reviews['visible'], $ids );
	}

	/** The Recent Reviews widget names the visible product but not the hidden one. */
	public function test_recent_reviews_widget_omits_pos_only_product_name(): void {
		ob_start();
		the_widget(
			'WC_Widget_Recent_Reviews',
			array(
				'title'  => 'Recent',
				'number' => 10,
			)
		);
		$output = ob_get_clean();
		$this->assertStringNotContainsString( 'Public Routes POS Only Alpha', $output );
		$this->assertStringContainsString( 'Public Routes Visible Bravo', $output );
	}

	/** A query pinned to a blog post keeps its exclusions and returns its comment. */
	public function test_comment_query_pinned_to_other_post_keeps_query_vars_unchanged(): void {
		$query    = new \WP_Comment_Query();
		$comments = $query->query( array( 'post_id' => $this->blog_post ) );
		$this->assertEmpty( $query->query_vars['post__not_in'] );
		$this->assertSame( array( $this->blog_comment ), array_map( 'intval', wp_list_pluck( $comments, 'comment_ID' ) ) );
	}

	/**
	 * Read the integer ids from a review or comment collection.
	 *
	 * @param WP_REST_Response $response The collection response.
	 */
	private function review_ids( $response ): array {
		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * Hook a fresh Products, as a new request would, and unhook every other instance.
	 */
	private function use_products(): void {
		$this->remove_products();
		Settings::instance()->reset_sections_for_testing();
		new Products();
	}

	/**
	 * Unhook every Products instance.
	 */
	private function remove_products(): void {
		foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( \is_array( $function ) && $function[0] instanceof Products ) {
						remove_filter( $hook_name, $function, $priority );
					}
				}
			}
		}
	}

	/**
	 * Turn POS Only products on or off, then start a new request.
	 *
	 * The object cache is not flushed: it lives for the whole PHP process, as a persistent cache lives across requests.
	 *
	 * @param bool $enabled Whether POS Only products are on.
	 */
	private function set_pos_only_flag_on_next_request( bool $enabled ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => $enabled ) );
		$this->use_products();
	}

	/**
	 * Dispatch a GET without adding the POS header.
	 *
	 * @param string $route  REST route to request.
	 * @param array  $params Query parameters.
	 */
	private function get( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Dispatch one request with the POS header, then restore the caller's context.
	 *
	 * @param callable $fn The request to dispatch.
	 *
	 * @return mixed The callable's result.
	 */
	private function as_pos( callable $fn ) {
		$previous                = $_SERVER['HTTP_X_WCPOS'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the test global verbatim to restore it.
		$_SERVER['HTTP_X_WCPOS'] = '1';
		try {
			return $fn();
		} finally {
			if ( null === $previous ) {
				unset( $_SERVER['HTTP_X_WCPOS'] );
			} else {
				$_SERVER['HTTP_X_WCPOS'] = $previous;
			}
		}
	}

	/**
	 * Enable pretty permalinks and install the product rewrite rules.
	 */
	private function pretty_permalinks(): void {
		$this->set_permalink_structure( '/%postname%/' );
		get_post_type_object( 'product' )->add_rewrite_rules();
		$GLOBALS['wp_rewrite']->flush_rules();
	}

	/**
	 * Compare the status, code and message with WordPress's error for a missing item.
	 *
	 * @param WP_REST_Response $expected Missing-item response.
	 * @param WP_REST_Response $actual   Hidden-item response.
	 */
	private function assert_same_error( $expected, $actual ): void {
		$this->assertSame( 404, $expected->get_status() );
		$this->assertSame( $expected->get_status(), $actual->get_status() );
		$this->assertSame( $expected->get_data()['code'], $actual->get_data()['code'] );
		$this->assertSame( $expected->get_data()['message'], $actual->get_data()['message'] );
	}
}
