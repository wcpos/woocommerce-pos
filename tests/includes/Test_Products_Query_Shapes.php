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
use WCPOS\WooCommercePOS\Tests\Reference_2_0_1\Products as Products_2_0_1;
use WP_Query;
use WP_REST_Request;

/**
 * Every query shape a storefront can build returns what 2.0.1 returned.
 *
 * @covers \WCPOS\WooCommercePOS\Products
 */
class Test_Products_Query_Shapes extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The sha1 of `git show v2.0.1:includes/Products.php` (tag v2.0.1, 82bf7d65), the reference every row is run against.
	 */
	const REFERENCE_2_0_1_SHA1 = '507aa31d2cbedc025fc15eb88e416d5ff6cbde1a';

	/**
	 * The Products class whose hooks are registered: the tree's or the 2.0.1 reference.
	 *
	 * @var string
	 */
	private $products_class = Products::class;

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
	 * A storefront query shape finds the subject on the tree exactly when it does on 2.0.1's Products.
	 *
	 * @dataProvider query_shapes
	 *
	 * @param string $shape             The query shape.
	 * @param string $subject           The fixture the shape targets.
	 * @param bool   $expected_on_2_0_1 Whether 2.0.1 returns the subject.
	 */
	public function test_query_shape_storefront_result_matches_2_0_1_reference( string $shape, string $subject, bool $expected_on_2_0_1 ): void {
		// Arrange.
		$id = $this->ids[ $subject ];
		if ( 0 === strpos( $shape, 'unsorted_list_' ) ) {
			$this->store_pos_only_list_unsorted( $id );
		}

		// Act.
		$this->use_products( self::load_reference_2_0_1() );
		$on_2_0_1 = $this->found( $shape, $id );
		wp_set_current_user( 0 );
		$this->go_to( wc_get_page_id( 'shop' ) );
		$this->use_products( Products::class );
		$on_tree = $this->found( $shape, $id );

		// Assert.
		$this->assertSame( $expected_on_2_0_1, $on_2_0_1, '2.0.1 reference' );
		$this->assertSame( $on_2_0_1, $on_tree, 'tree differs from 2.0.1' );
	}

	/**
	 * The reference really is 2.0.1's code: it writes the POS Only list into the SQL twice, the tree once.
	 */
	public function test_reference_2_0_1_writes_the_pos_only_list_twice_and_the_tree_once(): void {
		// Arrange.
		$pos_only = '/\b' . $this->ids['pos_only_product'] . '\b/';
		$args     = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'fields'      => 'ids',
		);

		// Act.
		$this->use_products( self::load_reference_2_0_1() );
		$on_2_0_1 = ( new WP_Query( $args ) )->request;
		$this->use_products( Products::class );
		$on_tree = ( new WP_Query( $args ) )->request;

		// Assert.
		$this->assertSame( 2, preg_match_all( $pos_only, $on_2_0_1 ) );
		$this->assertSame( 1, preg_match_all( $pos_only, $on_tree ) );
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
			array( 'post__in_negative', 'pos_only_product', false ),
			array( 'post__in_negative', 'visible_product', true ),
			array( 'post__in_negative', 'pos_only_variation', false ),
			array( 'post__in_negative', 'visible_variation', true ),
			array( 'post__in_negative_string', 'pos_only_product', false ),
			array( 'post__in_negative_string', 'visible_product', true ),
			array( 'post__in_negative_string', 'pos_only_variation', false ),
			array( 'post__in_negative_string', 'visible_variation', true ),
			array( 'post__in_numeric_string', 'pos_only_product', false ),
			array( 'post__in_numeric_string', 'visible_product', true ),
			array( 'post__in_numeric_string', 'pos_only_variation', false ),
			array( 'post__in_numeric_string', 'visible_variation', true ),
			array( 'post__in_duplicate', 'pos_only_product', false ),
			array( 'post__in_duplicate', 'visible_product', true ),
			array( 'post__in_duplicate', 'pos_only_variation', false ),
			array( 'post__in_duplicate', 'visible_variation', true ),
			array( 'post__in_zero_and_id', 'pos_only_product', false ),
			array( 'post__in_zero_and_id', 'visible_product', true ),
			array( 'post__in_zero_and_id', 'pos_only_variation', false ),
			array( 'post__in_zero_and_id', 'visible_variation', true ),
			array( 'post__in_non_numeric_and_id', 'pos_only_product', false ),
			array( 'post__in_non_numeric_and_id', 'visible_product', true ),
			array( 'post__in_non_numeric_and_id', 'pos_only_variation', false ),
			array( 'post__in_non_numeric_and_id', 'visible_variation', true ),
			array( 'third_party_post__in_negative_after_us', 'pos_only_product', false ),
			array( 'third_party_post__in_negative_after_us', 'visible_product', true ),
			array( 'third_party_post__in_negative_after_us', 'pos_only_variation', false ),
			array( 'third_party_post__in_negative_after_us', 'visible_variation', true ),
			array( 'third_party_post__not_in_negative_after_us', 'pos_only_product', false ),
			array( 'third_party_post__not_in_negative_after_us', 'visible_product', true ),
			array( 'third_party_post__not_in_negative_after_us', 'pos_only_variation', false ),
			array( 'third_party_post__not_in_negative_after_us', 'visible_variation', true ),
			array( 'third_party_p_after_us', 'pos_only_product', false ),
			array( 'third_party_p_after_us', 'visible_product', true ),
			array( 'third_party_p_after_us', 'pos_only_variation', false ),
			array( 'third_party_p_after_us', 'visible_variation', true ),
			array( 'third_party_post_type_after_us', 'pos_only_product', false ),
			array( 'third_party_post_type_after_us', 'visible_product', true ),
			array( 'third_party_post_type_after_us', 'pos_only_variation', false ),
			array( 'third_party_post_type_after_us', 'visible_variation', true ),
			array( 'third_party_post_type_any_after_us', 'pos_only_product', true ), // #2651: post_type any, so no exclusion.
			array( 'third_party_post_type_any_after_us', 'visible_product', true ),
			array( 'post_type_product_and_variation', 'pos_only_product', false ),
			array( 'post_type_product_and_variation', 'visible_product', true ),
			array( 'post_type_product_and_variation', 'pos_only_variation', false ),
			array( 'post_type_product_and_variation', 'visible_variation', true ),
			array( 'post_type_with_post', 'pos_only_product', false ),
			array( 'post_type_with_post', 'visible_product', true ),
			array( 'post_type_with_post', 'pos_only_variation', false ),
			array( 'post_type_with_post', 'visible_variation', true ),
			array( 'page_id_is_page_for_posts', 'pos_only_product', false ),
			array( 'page_id_is_page_for_posts', 'visible_product', true ),
			array( 'page_id_is_page_for_posts', 'pos_only_variation', false ),
			array( 'page_id_is_page_for_posts', 'visible_variation', true ),
			array( 'title', 'pos_only_product', false ),
			array( 'title', 'visible_product', true ),
			array( 'title', 'pos_only_variation', false ),
			array( 'title', 'visible_variation', true ),
			array( 'sitemap_products', 'pos_only_product', false ),
			array( 'sitemap_products', 'visible_product', true ),
			array( 'store_api_list_include', 'pos_only_product', false ),
			array( 'store_api_list_include', 'visible_product', true ),
			array( 'store_api_list_include', 'pos_only_variation', false ),
			array( 'store_api_list_include', 'visible_variation', true ),
			array( 'store_api_list_exclude_control', 'pos_only_product', false ),
			array( 'store_api_list_exclude_control', 'visible_product', true ),
			array( 'store_api_list_exclude_control', 'pos_only_variation', false ),
			array( 'store_api_list_exclude_control', 'visible_variation', true ),
			array( 'shortcode_products_ids', 'pos_only_product', false ),
			array( 'shortcode_products_ids', 'visible_product', true ),
			array( 'shortcode_products_ids_negative', 'pos_only_product', false ),
			array( 'shortcode_products_ids_negative', 'visible_product', true ),
			array( 'block_product_query', 'pos_only_product', false ),
			array( 'block_product_query', 'visible_product', true ),
			array( 'flag_off_on_next_request', 'pos_only_product', true ), // By design: the merchant turned POS Only off.
			array( 'flag_off_on_next_request', 'visible_product', true ),
			array( 'flag_off_on_next_request', 'pos_only_variation', true ), // By design: the merchant turned POS Only off.
			array( 'flag_off_on_next_request', 'visible_variation', true ),
			array( 'flag_on_again_on_next_request', 'pos_only_product', false ),
			array( 'flag_on_again_on_next_request', 'visible_product', true ),
			array( 'flag_on_again_on_next_request', 'pos_only_variation', false ),
			array( 'flag_on_again_on_next_request', 'visible_variation', true ),
			array( 'third_party_p_hex_after_us', 'pos_only_product', false ),
			array( 'third_party_p_hex_after_us', 'visible_product', true ),
			array( 'third_party_p_hex_after_us', 'pos_only_variation', false ),
			array( 'third_party_p_hex_after_us', 'visible_variation', true ),
			array( 'third_party_p_binary_after_us', 'pos_only_product', false ),
			array( 'third_party_p_binary_after_us', 'visible_product', true ),
			array( 'third_party_p_binary_after_us', 'pos_only_variation', false ),
			array( 'third_party_p_binary_after_us', 'visible_variation', true ),
			array( 'third_party_page_id_hex_after_us', 'pos_only_product', false ),
			array( 'third_party_page_id_hex_after_us', 'visible_product', true ),
			array( 'third_party_page_id_hex_after_us', 'pos_only_variation', false ),
			array( 'third_party_page_id_hex_after_us', 'visible_variation', true ),
			array( 'third_party_page_id_binary_after_us', 'pos_only_product', false ),
			array( 'third_party_page_id_binary_after_us', 'visible_product', true ),
			array( 'third_party_page_id_binary_after_us', 'pos_only_variation', false ),
			array( 'third_party_page_id_binary_after_us', 'visible_variation', true ),
			array( 'third_party_page_id_after_us', 'pos_only_product', false ),
			array( 'third_party_page_id_after_us', 'visible_product', true ),
			array( 'third_party_page_id_after_us', 'pos_only_variation', false ),
			array( 'third_party_page_id_after_us', 'visible_variation', true ),
			array( 'unsorted_list_default', 'pos_only_product', false ),
			array( 'unsorted_list_default', 'visible_product', true ),
			array( 'unsorted_list_default', 'pos_only_variation', false ),
			array( 'unsorted_list_default', 'visible_variation', true ),
			array( 'unsorted_list_post__in', 'pos_only_product', false ),
			array( 'unsorted_list_post__in', 'visible_product', true ),
			array( 'unsorted_list_post__in', 'pos_only_variation', false ),
			array( 'unsorted_list_post__in', 'visible_variation', true ),
			array( 'unsorted_list_post__not_in_other', 'pos_only_product', false ),
			array( 'unsorted_list_post__not_in_other', 'visible_product', true ),
			array( 'unsorted_list_post__not_in_other', 'pos_only_variation', false ),
			array( 'unsorted_list_post__not_in_other', 'visible_variation', true ),
			array( 'unsorted_list_late_post__not_in', 'pos_only_product', false ),
			array( 'unsorted_list_late_post__not_in', 'visible_product', true ),
			array( 'unsorted_list_late_post__not_in', 'pos_only_variation', false ),
			array( 'unsorted_list_late_post__not_in', 'visible_variation', true ),
			array( 'query_run_twice', 'pos_only_product', false ),
			array( 'query_run_twice', 'visible_product', true ),
			array( 'query_run_twice', 'pos_only_variation', false ),
			array( 'query_run_twice', 'visible_variation', true ),
			array( 'unsorted_list_query_run_twice', 'pos_only_product', false ),
			array( 'unsorted_list_query_run_twice', 'visible_product', true ),
			array( 'unsorted_list_query_run_twice', 'pos_only_variation', false ),
			array( 'unsorted_list_query_run_twice', 'visible_variation', true ),
			array( 'sticky_posts', 'pos_only_product', false ),
			array( 'sticky_posts', 'visible_product', true ),
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
			case 'unsorted_list_post__in':
				$args['post__in'] = array( $id, $fixture['control'] );
				break;
			case 'unsorted_list_default':
				break;
			case 'unsorted_list_post__not_in_other':
				$args['post__not_in'] = array( PHP_INT_MAX );
				break;
			case 'query_run_twice':
			case 'unsorted_list_query_run_twice':
				return \in_array( $id, $this->post_ids( ( new WP_Query( $args ) )->get_posts() ), true );
			case 'sticky_posts':
				// WordPress adds sticky posts to a home query only, and only when its posts are objects.
				update_option( 'sticky_posts', array( $id ) );
				$args['post_type'] = array( 'post', $fixture['type'] );
				$args['fields']    = '';
				$query             = new WP_Query( $args );
				$this->assertTrue( $query->is_home(), 'precondition: a home query' );

				return \in_array( $id, $this->post_ids( $query->posts ), true );
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
			case 'post__in_negative':
				$args['post__in'] = array( -$id );
				break;
			case 'post__in_negative_string':
				$args['post__in'] = array( '-' . $id );
				break;
			case 'post__in_numeric_string':
				$args['post__in'] = array( (string) $id );
				break;
			case 'post__in_duplicate':
				$args['post__in'] = array( $id, $id );
				break;
			case 'post__in_zero_and_id':
				$args['post__in'] = array( 0, $id );
				break;
			case 'post__in_non_numeric_and_id':
				$args['post__in'] = array( 'abc', $id );
				break;
			case 'post_type_product_and_variation':
				$args['post_type'] = array( 'product', 'product_variation' );
				$args['post__in']  = array( $id, $fixture['control'] );
				break;
			case 'post_type_with_post':
				$args['post_type'] = array( 'post', $fixture['type'] );
				$args['post__in']  = array( $id, $fixture['control'] );
				break;
			case 'page_id_is_page_for_posts':
				// WordPress copies page_id into p unless it is the posts page of a static front page.
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
				update_option( 'page_for_posts', $id );
				$args['page_id'] = $id;
				break;
			case 'title':
				$args['title'] = $fixture['title'];
				break;
			case 'third_party_post__not_in_after_us':
			case 'unsorted_list_late_post__not_in':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'post__not_in' => array( PHP_INT_MAX ) ) ), true );
			case 'third_party_post__in_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'post__in' => array( $id, $fixture['control'] ) ) ), true );
			case 'third_party_post__in_negative_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'post__in' => array( -$id ) ) ), true );
			case 'third_party_post__not_in_negative_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'post__not_in' => array( '-' . $fixture['control'] ) ) ), true );
			case 'third_party_p_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'p' => $id ) ), true );
			case 'third_party_p_hex_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'p' => sprintf( '0x%X', $id ) ) ), true );
			case 'third_party_p_binary_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'p' => '0b' . decbin( $id ) ) ), true );
			case 'third_party_page_id_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'page_id' => $id ) ), true );
			case 'third_party_page_id_hex_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'page_id' => sprintf( '0x%X', $id ) ) ), true );
			case 'third_party_page_id_binary_after_us':
				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'page_id' => '0b' . decbin( $id ) ) ), true );
			case 'third_party_post_type_after_us':
				$args['post_type'] = 'post';

				return \in_array( $id, $this->ids_with_late_callback( $args, array( 'post_type' => $fixture['type'] ) ), true );
			case 'third_party_post_type_any_after_us':
				return \in_array(
					$id,
					$this->ids_with_late_callback(
						$args,
						array(
							'post_type' => 'any',
							'p'         => $id,
						)
					),
					true
				);
			case 'flag_off_on_next_request':
			case 'flag_on_again_on_next_request':
				$this->set_pos_only_flag_on_next_request( true );
				$this->found( 'post__in_only', $id );
				$this->set_pos_only_flag_on_next_request( false );
				if ( 'flag_on_again_on_next_request' === $shape ) {
					$this->found( 'post__in_only', $id );
					$this->set_pos_only_flag_on_next_request( true );
				}

				return $this->found( 'post__in_only', $id );
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
			case 'sitemap_products':
				$urls = wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_url_list( 1, 'product' );

				return \in_array( get_permalink( $id ), wp_list_pluck( $urls, 'loc' ), true );
			case 'store_api_list_include':
			case 'store_api_list_exclude_control':
				$params = 'store_api_list_include' === $shape
					? array( 'include' => (string) $id )
					: array(
						'exclude'  => (string) $fixture['control'],
						'per_page' => '100',
					);
				if ( ! $is_product ) {
					$params['type'] = 'variation';
				}
				$request = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
				$request->set_query_params( $params );
				$response = rest_do_request( $request );
				$this->assertSame( 200, $response->get_status() );

				return \in_array( $id, $this->post_ids( wp_list_pluck( $response->get_data(), 'id' ) ), true );
			case 'shortcode_products_ids':
			case 'shortcode_products_ids_negative':
				// The shortcode caches its result by query args, which would hand the tree run the reference run's result.
				$no_cache = static function ( $atts ) {
					$atts['cache'] = false;

					return $atts;
				};
				add_filter( 'shortcode_atts_products', $no_cache );
				$html = do_shortcode( '[products ids="' . ( 'shortcode_products_ids' === $shape ? '' : '-' ) . $id . '"]' );
				remove_filter( 'shortcode_atts_products', $no_cache );

				return 1 === preg_match( '/\bpost-' . $id . '\b/', $html );
			case 'block_product_query':
				// The block caches its result by query args, which would hand the tree run the reference run's result.
				add_filter( 'woocommerce_blocks_product_grid_is_cacheable', '__return_false' );
				$html = do_blocks( '<!-- wp:woocommerce/handpicked-products {"products":[' . $id . ']} /-->' );
				remove_filter( 'woocommerce_blocks_product_grid_is_cacheable', '__return_false' );

				return false !== strpos( $html, $fixture['title'] );
		}

		$this->fail( 'Unknown shape ' . $shape );
	}

	/**
	 * Run a query whose vars are overwritten by a pre_get_posts callback after ours.
	 *
	 * @param array $args Query args.
	 * @param array $vars The query vars the callback sets, by name.
	 *
	 * @return int[]
	 */
	private function ids_with_late_callback( array $args, array $vars ): array {
		$query    = new WP_Query();
		$callback = static function ( $candidate ) use ( $query, $vars ) {
			if ( $candidate === $query ) {
				foreach ( $vars as $query_var => $value ) {
					$candidate->set( $query_var, $value );
				}
			}
		};
		add_action( 'pre_get_posts', $callback, 20 );
		$query->query( $args );
		remove_action( 'pre_get_posts', $callback, 20 );

		return $this->post_ids( $query->posts );
	}

	/**
	 * Load 2.0.1's Products.php, byte-identical, as a class in its own namespace.
	 *
	 * @return string The reference class name.
	 */
	private static function load_reference_2_0_1(): string {
		$contents = file_get_contents( dirname( __DIR__ ) . '/fixtures/products-2.0.1.php.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local fixture.
		if ( sha1( (string) $contents ) !== self::REFERENCE_2_0_1_SHA1 ) {
			self::fail( 'tests/fixtures/products-2.0.1.php.txt is not v2.0.1:includes/Products.php' );
		}

		if ( ! class_exists( Products_2_0_1::class, false ) ) {
			$replacements = 0;
			$source       = str_replace( 'namespace WCPOS\WooCommercePOS;', 'namespace WCPOS\WooCommercePOS\Tests\Reference_2_0_1;', $contents, $replacements );
			if ( 1 !== $replacements ) {
				self::fail( 'Expected one namespace line in the 2.0.1 reference, found ' . $replacements );
			}
			$file = get_temp_dir() . 'wcpos-products-2.0.1-' . getmypid() . '.php';
			file_put_contents( $file, $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file for require_once.
			require_once $file;
			wp_delete_file( $file );
		}

		return Products_2_0_1::class;
	}

	/**
	 * Hook one Products class, as a new request would, and unhook every other instance.
	 *
	 * @param string $class The tree's Products or the 2.0.1 reference.
	 */
	private function use_products( string $class ): void {
		foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( \is_array( $function ) && ( $function[0] instanceof Products || $function[0] instanceof Products_2_0_1 ) ) {
						remove_filter( $hook_name, $function, $priority );
					}
				}
			}
		}
		Settings::instance()->reset_sections_for_testing();
		$this->products_class = $class;
		new $class();
	}

	/**
	 * Turn POS Only products on or off, then start a new request with the class under test.
	 *
	 * The object cache is not flushed: it lives for the whole PHP process, as a persistent cache lives across requests.
	 *
	 * @param bool $enabled Whether POS Only products are on.
	 */
	private function set_pos_only_flag_on_next_request( bool $enabled ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => $enabled ) );
		$this->use_products( $this->products_class );
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

	/**
	 * Store the subject type's POS Only list out of ascending order: the fixture's item between two extra ones.
	 *
	 * WordPress sorts post__not_in before writing it, so the list no longer equals the POS Only ids it was built from.
	 *
	 * @param int $id The subject id.
	 */
	private function store_pos_only_list_unsorted( int $id ): void {
		$is_product = 'product' === $this->fixtures[ $id ]['type'];
		$post_type  = $is_product ? 'products' : 'variations';
		$pos_only   = $this->ids[ $is_product ? 'pos_only_product' : 'pos_only_variation' ];
		$extras     = $is_product
			? array( $this->create_simple( 'Query Shape POS Only Charlie', 'QS-POS-ONLY-CHARLIE' ), $this->create_simple( 'Query Shape POS Only Delta', 'QS-POS-ONLY-DELTA' ) )
			: array_slice( array_map( 'intval', ProductHelper::create_variation_product()->get_children() ), 0, 2 );
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => $post_type,
				'visibility' => '',
				'ids'        => array( $pos_only ),
				'scope'      => 'default',
			)
		);
		foreach ( array( $extras[1], $pos_only, $extras[0] ) as $pos_only_id ) {
			$this->mark_pos_only( $post_type, $pos_only_id );
		}

		Settings::instance()->reset_sections_for_testing();
		$settings = $is_product ? Settings::instance()->get_pos_only_product_visibility_settings() : Settings::instance()->get_pos_only_variations_visibility_settings();
		$stored   = array_map( 'intval', $settings['ids'] );
		$sorted   = $stored;
		sort( $sorted );
		$this->assertSame( array( $extras[1], $pos_only, $extras[0] ), $stored, 'precondition: stored POS Only list' );
		$this->assertNotSame( $sorted, $stored, 'precondition: the stored POS Only list is not ascending' );
	}
}
