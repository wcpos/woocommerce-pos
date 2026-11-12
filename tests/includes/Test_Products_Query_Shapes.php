<?php
/**
 * Storefront query shapes against POS Only products and variations.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Install;
use WC_Product;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_Query;
use WP_REST_Request;

/**
 * Every query shape a storefront can build returns what 2.0.1 returned.
 *
 * @covers \WCPOS\WooCommercePOS\Products
 */
class Test_Products_Query_Shapes extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Fixture ids by subject name.
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * Fixture details by id: type, slug, title, sku, control id and parent id.
	 *
	 * @var array<int, array{type: string, slug: string, title: string, sku: string, control: int, parent: int}>
	 */
	private $fixtures = array();

	/**
	 * The product_cat term both simple products carry.
	 *
	 * @var int
	 */
	private $term_id = 0;

	/**
	 * A POS Only simple product and variation, each with a visible control, on the shop page, logged out.
	 */
	public function setUp(): void {
		parent::setUp();
		Settings::instance()->reset_sections_for_testing();
		delete_option( Pos_Visibility::OPTION );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		new Products();

		$this->term_id = (int) wp_insert_term( 'Query Shape Category', 'product_cat' )['term_id'];
		$pos_only      = $this->create_simple( 'Query Shape POS Only Alpha', 'QS-POS-ONLY-ALPHA' );
		$visible       = $this->create_simple( 'Query Shape Visible Bravo', 'QS-VISIBLE-BRAVO' );
		$this->add_fixture( 'pos_only_product', $pos_only, $visible, 0 );
		$this->add_fixture( 'visible_product', $visible, $pos_only, 0 );

		$variable = ProductHelper::create_variation_product();
		$children = array_map( 'intval', $variable->get_children() );
		$this->add_fixture( 'pos_only_variation', $children[0], $children[1], $variable->get_id() );
		$this->add_fixture( 'visible_variation', $children[1], $children[0], $variable->get_id() );

		$this->mark_pos_only( 'products', $pos_only );
		$this->mark_pos_only( 'variations', $children[0] );

		WC_Install::create_pages();
		$this->go_to( wc_get_page_id( 'shop' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Remove the visibility settings.
	 */
	public function tearDown(): void {
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		Settings::instance()->reset_sections_for_testing();
		parent::tearDown();
	}

	/**
	 * A storefront query shape finds the subject exactly when 2.0.1 did.
	 *
	 * @dataProvider query_shapes
	 *
	 * @param string $shape          The query shape.
	 * @param string $subject        The fixture the shape targets.
	 * @param bool   $expected_found Whether the subject comes back.
	 */
	public function test_query_shape_storefront_result_matches_2_0_1( string $shape, string $subject, bool $expected_found ): void {
		// Arrange.
		$id = $this->ids[ $subject ];

		// Act.
		$found = $this->found( $shape, $id );

		// Assert.
		$this->assertSame( $expected_found, $found );
	}

	/**
	 * Data provider: shape, subject, whether 2.0.1 returns the subject.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public function query_shapes(): array {
		$rows = array(
			array( 'p', 'pos_only_product', false ),
			array( 'p', 'visible_product', true ),
			array( 'p', 'pos_only_variation', false ),
			array( 'p', 'visible_variation', true ),
			array( 'page_id', 'pos_only_product', false ),
			array( 'page_id', 'visible_product', true ),
			array( 'page_id', 'pos_only_variation', false ),
			array( 'page_id', 'visible_variation', true ),
			array( 'attachment_id', 'pos_only_product', false ),
			array( 'attachment_id', 'visible_product', true ),
			array( 'attachment_id', 'pos_only_variation', false ),
			array( 'attachment_id', 'visible_variation', true ),
			array( 'p_with_other_post__in', 'pos_only_product', false ),
			array( 'p_with_other_post__in', 'visible_product', true ),
			array( 'p_with_other_post__in', 'pos_only_variation', false ),
			array( 'p_with_other_post__in', 'visible_variation', true ),
			array( 'name', 'pos_only_product', false ),
			array( 'name', 'visible_product', true ),
			array( 'name', 'pos_only_variation', false ),
			array( 'name', 'visible_variation', true ),
			array( 'post_name__in', 'pos_only_product', false ),
			array( 'post_name__in', 'visible_product', true ),
			array( 'post_name__in', 'pos_only_variation', false ),
			array( 'post_name__in', 'visible_variation', true ),
			array( 'post__in_with_others', 'pos_only_product', false ),
			array( 'post__in_with_others', 'visible_product', true ),
			array( 'post__in_with_others', 'pos_only_variation', false ),
			array( 'post__in_with_others', 'visible_variation', true ),
			array( 'post__in_only', 'pos_only_product', false ),
			array( 'post__in_only', 'visible_product', true ),
			array( 'post__in_only', 'pos_only_variation', false ),
			array( 'post__in_only', 'visible_variation', true ),
			array( 'post_parent', 'pos_only_variation', false ),
			array( 'post_parent', 'visible_variation', true ),
			array( 'post_parent__in', 'pos_only_variation', false ),
			array( 'post_parent__in', 'visible_variation', true ),
			array( 's', 'pos_only_product', false ),
			array( 's', 'visible_product', true ),
			array( 's', 'pos_only_variation', false ),
			array( 's', 'visible_variation', true ),
			array( 's_no_post_type', 'pos_only_product', true ), // #2651: no post_type, so no exclusion.
			array( 's_no_post_type', 'visible_product', true ),
			array( 'tax_query', 'pos_only_product', false ),
			array( 'tax_query', 'visible_product', true ),
			array( 'meta_query', 'pos_only_product', false ),
			array( 'meta_query', 'visible_product', true ),
			array( 'meta_query', 'pos_only_variation', false ),
			array( 'meta_query', 'visible_variation', true ),
			array( 'fields_all', 'pos_only_product', false ),
			array( 'fields_all', 'visible_product', true ),
			array( 'fields_all', 'pos_only_variation', false ),
			array( 'fields_all', 'visible_variation', true ),
			array( 'suppress_filters_list', 'pos_only_product', false ),
			array( 'suppress_filters_list', 'visible_product', true ),
			array( 'suppress_filters_list', 'pos_only_variation', false ),
			array( 'suppress_filters_list', 'visible_variation', true ),
			array( 'suppress_filters_p', 'pos_only_product', true ), // 2.0.1 leak: p drops post__not_in and posts_where is suppressed.
			array( 'suppress_filters_p', 'visible_product', true ),
			array( 'suppress_filters_p', 'pos_only_variation', true ), // 2.0.1 leak: p drops post__not_in and posts_where is suppressed.
			array( 'suppress_filters_p', 'visible_variation', true ),
			array( 'post_type_any_p', 'pos_only_product', true ), // #2651: post_type any, so no exclusion.
			array( 'post_type_any_p', 'visible_product', true ),
			array( 'wc_get_products_include', 'pos_only_product', false ),
			array( 'wc_get_products_include', 'visible_product', true ),
			array( 'wc_get_products_include', 'pos_only_variation', false ),
			array( 'wc_get_products_include', 'visible_variation', true ),
			array( 'wc_get_product_direct', 'pos_only_product', true ), // By design: a direct load is not a listing.
			array( 'wc_get_product_direct', 'visible_product', true ),
			array( 'wc_get_product_direct', 'pos_only_variation', true ), // By design: a direct load is not a listing.
			array( 'wc_get_product_direct', 'visible_variation', true ),
			array( 'is_visible', 'pos_only_product', false ),
			array( 'is_visible', 'visible_product', true ),
			array( 'is_visible', 'pos_only_variation', false ),
			array( 'is_visible', 'visible_variation', true ),
			array( 'single_product_url', 'pos_only_product', false ),
			array( 'single_product_url', 'visible_product', true ),
			array( 'feed', 'pos_only_product', false ),
			array( 'feed', 'visible_product', true ),
			array( 'store_api_single', 'pos_only_product', true ), // 2.0.1 behaviour recorded: the by-id route loads the product directly, no listing query.
			array( 'store_api_single', 'visible_product', true ),
			array( 'store_api_single', 'pos_only_variation', true ), // 2.0.1 behaviour recorded: the by-id route loads the variation directly, no listing query.
			array( 'store_api_single', 'visible_variation', true ),
			array( 'wc_v3_single', 'pos_only_product', true ), // By design: wc/v3 is the operator's namespace.
			array( 'wc_v3_single', 'visible_product', true ),
			array( 'wc_v3_single', 'pos_only_variation', true ), // By design: wc/v3 is the operator's namespace.
			array( 'wc_v3_single', 'visible_variation', true ),
			array( 'third_party_post__not_in_after_us', 'pos_only_product', false ),
			array( 'third_party_post__not_in_after_us', 'visible_product', true ),
			array( 'third_party_post__not_in_after_us', 'pos_only_variation', false ),
			array( 'third_party_post__not_in_after_us', 'visible_variation', true ),
			array( 'third_party_post__in_after_us', 'pos_only_product', false ),
			array( 'third_party_post__in_after_us', 'visible_product', true ),
			array( 'third_party_post__in_after_us', 'pos_only_variation', false ),
			array( 'third_party_post__in_after_us', 'visible_variation', true ),
			array( 'children_built_on_storefront', 'pos_only_variation', false ),
			array( 'children_built_on_storefront', 'visible_variation', true ),
			array( 'children_built_outside_storefront', 'pos_only_variation', true ), // #2652: the cached children list is reused.
			array( 'children_built_outside_storefront', 'visible_variation', true ),
		);

		$data = array();
		foreach ( $rows as $row ) {
			$data[ $row[0] . ' ' . $row[1] ] = $row;
		}

		return $data;
	}

	/**
	 * Run one shape for a subject and report whether the subject came back.
	 *
	 * @param string $shape The query shape.
	 * @param int    $id    The subject id.
	 */
	private function found( string $shape, int $id ): bool {
		$fixture = $this->fixtures[ $id ];
		$args    = array(
			'post_type'      => $fixture['type'],
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);

		switch ( $shape ) {
			case 'p':
			case 'page_id':
			case 'attachment_id':
				$args[ $shape ] = $id;
				break;
			case 'p_with_other_post__in':
				$args['p']        = $id;
				$args['post__in'] = array( $fixture['control'] );
				break;
			case 'name':
				$args[ $shape ] = $fixture['slug'];
				break;
			case 'post_name__in':
				$args['post_name__in'] = array( $fixture['slug'] );
				break;
			case 'post__in_with_others':
				$args['post__in'] = array( $id, $fixture['control'] );
				break;
			case 'post__in_only':
				$args['post__in'] = array( $id );
				break;
			case 'post_parent':
				$args['post_parent'] = $fixture['parent'];
				break;
			case 'post_parent__in':
				$args['post_parent__in'] = array( $fixture['parent'] );
				break;
			case 's':
				$args['s'] = $fixture['title'];
				break;
			case 's_no_post_type':
				$args['s'] = $fixture['title'];
				unset( $args['post_type'] );
				break;
			case 'tax_query':
				$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the shape under test.
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $this->term_id,
					),
				);
				break;
			case 'meta_query':
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the shape under test.
					array(
						'key'   => '_sku',
						'value' => $fixture['sku'],
					),
				);
				break;
			case 'fields_all':
				$args['fields'] = '';
				break;
			case 'suppress_filters_list':
				$args['suppress_filters'] = true;
				break;
			case 'suppress_filters_p':
				$args['suppress_filters'] = true;
				$args['p']                = $id;
				break;
			case 'post_type_any_p':
				$args['post_type'] = 'any';
				$args['p']         = $id;
				break;
			case 'third_party_post__not_in_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, 'post__not_in', array( PHP_INT_MAX ) ), true );
			case 'third_party_post__in_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, 'post__in', array( $id, $fixture['control'] ) ), true );
			default:
				return $this->found_outside_wp_query( $shape, $id );
		}

		return \in_array( $id, $this->post_ids( ( new WP_Query( $args ) )->posts ), true );
	}

	/**
	 * Run a shape that does not build its own WP_Query.
	 *
	 * @param string $shape The shape.
	 * @param int    $id    The subject id.
	 */
	private function found_outside_wp_query( string $shape, int $id ): bool {
		$fixture    = $this->fixtures[ $id ];
		$is_product = 'product' === $fixture['type'];

		switch ( $shape ) {
			case 'wc_get_products_include':
				$ids = wc_get_products(
					array(
						'include' => array( $id ),
						'type'    => $is_product ? 'simple' : 'variation',
						'return'  => 'ids',
						'limit'   => -1,
					)
				);

				return \in_array( $id, $this->post_ids( $ids ), true );
			case 'wc_get_product_direct':
				return wc_get_product( $id ) instanceof WC_Product;
			case 'is_visible':
				if ( $is_product ) {
					return wc_get_product( $id )->is_visible();
				}
				// go_to() needs a URL to land on the shop page, where variation visibility is decided.
				$this->go_to( get_permalink( wc_get_page_id( 'shop' ) ) );
				$this->assertTrue( is_shop() );

				return wc_get_product( $id )->variation_is_visible();
			case 'single_product_url':
				$this->go_to( get_permalink( $id ) );

				return ! is_404() && \in_array( $id, $this->post_ids( $GLOBALS['wp_query']->posts ), true );
			case 'feed':
				$this->go_to(
					add_query_arg(
						array(
							'feed'      => 'rss2',
							'post_type' => 'product',
						),
						home_url( '/' )
					)
				);

				return \in_array( $id, $this->post_ids( $GLOBALS['wp_query']->posts ), true );
			case 'store_api_single':
				return 200 === rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $id ) )->get_status();
			case 'wc_v3_single':
				wp_set_current_user( $this->user );
				$route = $is_product ? '/wc/v3/products/' . $id : '/wc/v3/products/' . $fixture['parent'] . '/variations/' . $id;

				return 200 === rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
			case 'children_built_on_storefront':
				delete_transient( 'wc_product_children_' . $fixture['parent'] );

				return \in_array( $id, $this->post_ids( wc_get_product( $fixture['parent'] )->get_children() ), true );
			case 'children_built_outside_storefront':
				delete_transient( 'wc_product_children_' . $fixture['parent'] );
				add_filter( 'wcpos_hide_pos_only_products', '__return_false' );
				wc_get_product( $fixture['parent'] )->get_children();
				remove_filter( 'wcpos_hide_pos_only_products', '__return_false' );

				return \in_array( $id, $this->post_ids( wc_get_product( $fixture['parent'] )->get_children() ), true );
		}

		$this->fail( 'Unknown shape ' . $shape );
	}

	/**
	 * Run a query whose var is overwritten by a pre_get_posts callback after ours.
	 *
	 * @param array  $args  Query args.
	 * @param string $query_var   The query var the callback sets.
	 * @param array  $value The value it sets.
	 *
	 * @return int[]
	 */
	private function ids_with_late_callback( array $args, string $query_var, array $value ): array {
		$query    = new WP_Query();
		$callback = static function ( $candidate ) use ( $query, $query_var, $value ) {
			if ( $candidate === $query ) {
				$candidate->set( $query_var, $value );
			}
		};
		add_action( 'pre_get_posts', $callback, 20 );
		$query->query( $args );
		remove_action( 'pre_get_posts', $callback, 20 );

		return $this->post_ids( $query->posts );
	}

	/**
	 * Ids of a list of posts, ids or numeric strings.
	 *
	 * @param array $posts WP_Post objects or ids.
	 *
	 * @return int[]
	 */
	private function post_ids( array $posts ): array {
		return array_map(
			static function ( $post ) {
				return (int) ( \is_object( $post ) ? $post->ID : $post );
			},
			$posts
		);
	}

	/**
	 * Create a published simple product in the shared category.
	 *
	 * @param string $title Unique title; the slug is derived from it.
	 * @param string $sku   Unique SKU.
	 *
	 * @return int
	 */
	private function create_simple( string $title, string $sku ): int {
		return ProductHelper::create_simple_product(
			array(
				'name'         => $title,
				'slug'         => sanitize_title( $title ),
				'sku'          => $sku,
				'category_ids' => array( $this->term_id ),
			)
		)->get_id();
	}

	/**
	 * Record one subject.
	 *
	 * @param string $subject Subject name.
	 * @param int    $id      Post id.
	 * @param int    $control The visible or POS Only counterpart of the same kind.
	 * @param int    $parent_id  Parent id for a variation, 0 for a product.
	 */
	private function add_fixture( string $subject, int $id, int $control, int $parent_id ): void {
		$this->ids[ $subject ] = $id;
		$this->fixtures[ $id ] = array(
			'type'    => get_post_type( $id ),
			'slug'    => get_post_field( 'post_name', $id ),
			'title'   => get_post_field( 'post_title', $id ),
			'sku'     => wc_get_product( $id )->get_sku(),
			'control' => $control,
			'parent'  => $parent_id,
		);
	}

	/**
	 * Mark one id POS Only through the settings writer the admin uses.
	 *
	 * @param string $post_type 'products' or 'variations'.
	 * @param int    $id        Post id.
	 */
	private function mark_pos_only( string $post_type, int $id ): void {
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => $post_type,
				'visibility' => 'pos_only',
				'ids'        => array( $id ),
				'scope'      => 'default',
			)
		);
	}
}
