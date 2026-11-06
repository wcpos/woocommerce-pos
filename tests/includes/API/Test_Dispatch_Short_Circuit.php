<?php
/**
 * A WCPOS all-IDs answer survives a later rest_dispatch_request listener that returns null (#1197).
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The stand-in for WP Activity Log's Redirection sensor is `__return_null` on
 * rest_dispatch_request at PHP_INT_MAX, which makes core run the paginated callback.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Dispatch_Short_Circuit extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Records created per route: one more than the default page size of 10.
	 */
	private const RECORDS = 11;

	/**
	 * The API instance booted for this test.
	 *
	 * @var API
	 */
	private $api;

	/**
	 * Keep the API instance so its storage can be read.
	 */
	public function rest_api_init(): void {
		$this->api = new API();
	}

	/**
	 * Remove the stand-in.
	 */
	public function tearDown(): void {
		remove_filter( 'rest_dispatch_request', '__return_null', PHP_INT_MAX );
		parent::tearDown();
	}

	/**
	 * Every all-IDs route on this lane. wcpos/v2 has no products route here (FROZEN_DATA_KEYS).
	 *
	 * @return array
	 */
	public function all_ids_routes(): array {
		return array(
			'products'           => array( '/wcpos/v1/products', 'products' ),
			'product variations' => array( '/wcpos/v1/products/variations', 'variations' ),
			'customers'          => array( '/wcpos/v1/customers', 'customers' ),
			'coupons'            => array( '/wcpos/v1/coupons', 'coupons' ),
			'taxes'              => array( '/wcpos/v1/taxes', 'taxes' ),
			'orders'             => array( '/wcpos/v1/orders', 'orders' ),
			'product tags'       => array( '/wcpos/v1/products/tags', 'tags' ),
			'product categories' => array( '/wcpos/v1/products/categories', 'categories' ),
			'product brands'     => array( '/wcpos/v1/products/brands', 'brands' ),
		);
	}

	/**
	 * The null listener cannot swap every ID for one page.
	 *
	 * @dataProvider all_ids_routes
	 *
	 * @param string $route Route.
	 * @param string $kind  Record kind.
	 */
	public function test_all_ids_with_null_listener_returns_every_record( string $route, string $kind ): void {
		$created = $this->create_records( $kind );
		add_filter( 'rest_dispatch_request', '__return_null', PHP_INT_MAX, 4 );

		$response = $this->server->dispatch( $this->all_ids_request( $route ) );

		$this->assertSame( 200, $response->get_status() );
		$ids = array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( array(), array_values( array_diff( $created, $ids ) ) );
		$this->assertSame( 0, $this->stored_count() );
	}

	/**
	 * Without the null listener the answer is unchanged.
	 *
	 * @dataProvider all_ids_routes
	 *
	 * @param string $route Route.
	 * @param string $kind  Record kind.
	 */
	public function test_all_ids_without_null_listener_returns_every_record( string $route, string $kind ): void {
		$created = $this->create_records( $kind );

		$response = $this->server->dispatch( $this->all_ids_request( $route ) );

		$this->assertSame( 200, $response->get_status() );
		$ids = array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( array(), array_values( array_diff( $created, $ids ) ) );
		$this->assertSame( 0, $this->stored_count() );
	}

	/** A paginated GET is never remembered. */
	public function test_paginated_get_with_null_listener_returns_default_page(): void {
		$this->create_records( 'products' );
		add_filter( 'rest_dispatch_request', '__return_null', PHP_INT_MAX, 4 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/products' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 10, $response->get_data() );
		$this->assertSame( self::RECORDS, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 0, $this->stored_count() );
	}

	/** The write path is untouched, even with the all-IDs params on the POST. */
	public function test_post_with_null_listener_creates_product_and_stores_nothing(): void {
		add_filter( 'rest_dispatch_request', '__return_null', PHP_INT_MAX, 4 );
		$request = $this->wp_rest_post_request( '/wcpos/v1/products' );
		$request->set_body_params(
			array(
				'name'          => 'Short circuit write',
				'regular_price' => '5.00',
			)
		);
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$product = wc_get_product( $response->get_data()['id'] );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertSame( 'Short circuit write', $product->get_name() );
		$this->assertSame( 0, $this->stored_count() );
	}

	/** WCPOS's answer is restored first, so later listeners see it and keep their change. */
	public function test_after_callbacks_listener_sees_the_restored_answer_and_keeps_its_change(): void {
		$created  = $this->create_records( 'products' );
		$seen     = null;
		$replaced = new WP_REST_Response( array( array( 'id' => -1 ) ) );
		add_filter( 'rest_dispatch_request', '__return_null', PHP_INT_MAX, 4 );
		add_filter(
			'rest_request_after_callbacks',
			static function ( $response ) use ( &$seen, $replaced ) {
				$seen = array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );

				return $replaced;
			}
		);

		$response = $this->server->dispatch( $this->all_ids_request( '/wcpos/v1/products' ) );

		$this->assertSame( array(), array_values( array_diff( $created, $seen ) ) );
		$this->assertSame( array( array( 'id' => -1 ) ), $response->get_data() );
		$this->assertSame( 0, $this->stored_count() );
	}

	/** A later rest_dispatch_request refusal is never replaced by WCPOS data. */
	public function test_later_dispatch_listener_error_is_not_replaced(): void {
		$this->create_records( 'products' );
		$deny = static function () {
			return new \WP_Error( 'blocked', 'Blocked', array( 'status' => 403 ) );
		};
		add_filter( 'rest_dispatch_request', $deny, 20 );

		$response = $this->server->dispatch( $this->all_ids_request( '/wcpos/v1/products' ) );
		remove_filter( 'rest_dispatch_request', $deny, 20 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'blocked', $response->get_data()['code'] );
		$this->assertSame( 0, $this->stored_count() );
	}

	/** A nested rest_do_request keeps each response with its own request. */
	public function test_nested_all_ids_requests_keep_their_own_responses(): void {
		$products = $this->create_records( 'products' );
		$tags     = $this->create_records( 'tags' );
		$inner    = null;
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request ) use ( &$inner ) {
				if ( '/wcpos/v1/products' === $request->get_route() && null === $inner ) {
					$inner = rest_do_request( $this->all_ids_request( '/wcpos/v1/products/tags' ) );
				}
				return $result;
			},
			20,
			2
		);
		add_filter( 'rest_dispatch_request', '__return_null', PHP_INT_MAX, 4 );

		$outer = $this->server->dispatch( $this->all_ids_request( '/wcpos/v1/products' ) );

		$outer_ids = array_map( 'intval', wp_list_pluck( $outer->get_data(), 'id' ) );
		$inner_ids = array_map( 'intval', wp_list_pluck( $inner->get_data(), 'id' ) );
		$this->assertEqualsCanonicalizing( $products, $outer_ids );
		$this->assertSame( array(), array_values( array_diff( $tags, $inner_ids ) ) );
		$this->assertSame( array(), array_intersect( $products, $inner_ids ) );
		$this->assertSame( 0, $this->stored_count() );
	}

	/**
	 * An all-IDs GET.
	 *
	 * @param string $route Route.
	 *
	 * @return WP_REST_Request
	 */
	private function all_ids_request( string $route ): WP_REST_Request {
		$request = $this->wp_rest_get_request( $route );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		return $request;
	}

	/**
	 * Entries left in API::$short_circuits.
	 *
	 * @return int
	 */
	private function stored_count(): int {
		$property = new \ReflectionProperty( API::class, 'short_circuits' );
		$property->setAccessible( true );

		return \count( $property->getValue( $this->api ) );
	}

	/**
	 * Create more records than the default page size.
	 *
	 * @param string $kind Record kind.
	 *
	 * @return int[] Created ids.
	 */
	private function create_records( string $kind ): array {
		$ids = array();
		if ( 'variations' === $kind ) {
			// Each variable product carries two variations: six give twelve.
			for ( $i = 0; $i < 6; $i++ ) {
				$ids = array_merge( $ids, ProductHelper::create_variation_product()->get_children() );
			}
			return array_map( 'intval', $ids );
		}
		if ( 'brands' === $kind && ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'Product brand taxonomy is not available in this WooCommerce version.' );
		}
		for ( $i = 0; $i < self::RECORDS; $i++ ) {
			switch ( $kind ) {
				case 'products':
					$ids[] = ProductHelper::create_simple_product()->get_id();
					break;
				case 'customers':
					$ids[] = $this->factory->user->create( array( 'role' => 'customer' ) );
					break;
				case 'coupons':
					$ids[] = CouponHelper::create_coupon( 'shortcircuit' . $i )->get_id();
					break;
				case 'taxes':
					$ids[] = TaxHelper::create_tax_rate(
						array(
							'country' => 'US',
							'rate'    => '1',
							'name'    => 'Short circuit ' . $i,
						)
					);
					break;
				case 'orders':
					$ids[] = OrderHelper::create_order()->get_id();
					break;
				case 'tags':
					$ids[] = ProductHelper::create_product_tag( 'Short circuit tag ' . $i )['term_id'];
					break;
				case 'categories':
					$ids[] = ProductHelper::create_product_category( 'Short circuit category ' . $i )['term_id'];
					break;
				case 'brands':
					$ids[] = wp_insert_term( 'Short circuit brand ' . $i, 'product_brand' )['term_id'];
					break;
			}
		}

		return array_map( 'intval', $ids );
	}
}
