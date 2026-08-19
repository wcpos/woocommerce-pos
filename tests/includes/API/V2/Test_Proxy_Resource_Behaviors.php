<?php
/**
 * Tests for per-resource catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API\V2\Proxy\Coupons_Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Customers_Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Null_Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Orders_Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Products_Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Taxes_Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Terms_Proxy_Behavior;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Per-resource behavior is resolved from the collection registry and remains scoped to one forward.
 */
class Test_Proxy_Resource_Behaviors extends WP_UnitTestCase {
	/**
	 * Remove visibility settings and the current user after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Pos_Visibility::OPTION );
		parent::tearDown();
	}

	/**
	 * Every proxy row resolves its behavior without a second resource map.
	 */
	public function test_registry_resolves_behavior_for_every_proxy_collection(): void {
		$expected = array(
			'products'   => Products_Proxy_Behavior::class,
			'orders'     => Orders_Proxy_Behavior::class,
			'customers'  => Customers_Proxy_Behavior::class,
			// Terms carry the stable name-sort tiebreak since mono#1371 made
			// name asc their default walk order (see Terms_Proxy_Behavior).
			'categories' => Terms_Proxy_Behavior::class,
			'brands'     => Terms_Proxy_Behavior::class,
			'tags'       => Terms_Proxy_Behavior::class,
			'coupons'    => Coupons_Proxy_Behavior::class,
			'tax_rates'  => Taxes_Proxy_Behavior::class,
		);

		foreach ( Collections::with( 'proxy' ) as $collection => $row ) {
			$class = $row['proxy']['behavior'];

			$this->assertSame( $expected[ $collection ], $class );
			$this->assertInstanceOf( Proxy_Behavior::class, new $class() );
		}
	}

	/**
	 * The null behavior leaves every phase untouched.
	 */
	public function test_null_behavior_is_a_pass_through(): void {
		$behavior = new Null_Proxy_Behavior();
		$request  = new WP_REST_Request();
		$params   = array( 'page' => 3 );
		$data     = array( array( 'id' => 7 ) );

		$this->assertSame( $params, $behavior->forwarded_params( $params, $request ) );
		$this->assertSame( 'forwarded', $behavior->around( static fn() => 'forwarded' ) );
		$this->assertSame( $data, $behavior->post_process( $data ) );
	}

	/**
	 * Customer-only params are removed from wc/v3 and installed only around the forward.
	 */
	public function test_customer_behavior_moves_search_and_extended_sort(): void {
		$behavior = new Customers_Proxy_Behavior();
		$request  = new WP_REST_Request();
		$request->set_query_params(
			array(
				'search'  => ' Jane Doe ',
				'orderby' => 'email',
			)
		);

		$forwarded = $behavior->forwarded_params( $request->get_query_params(), $request );
		$prepared  = $behavior->around(
			static function (): array {
				return apply_filters( 'woocommerce_rest_customer_query', array( 'search' => '*Jane Doe*' ) );
			}
		);

		$this->assertSame( 'all', $forwarded['role'] );
		$this->assertArrayNotHasKey( 'search', $forwarded );
		$this->assertArrayNotHasKey( 'orderby', $forwarded );
		$this->assertArrayNotHasKey( 'search', $prepared );
		$this->assertSame( 'Jane Doe', $prepared['_wcpos_search'] );
		$this->assertSame( 'user_email', $prepared['orderby'] );
		$this->assertFalse( has_filter( 'woocommerce_rest_customer_query' ) );
		$this->assertFalse( has_filter( 'pre_user_query' ) );
	}

	/**
	 * Tax include IDs are renamed and applied by a scoped SQL clause.
	 */
	public function test_tax_behavior_moves_include_and_unwinds_sql_filter(): void {
		global $wpdb;

		$behavior = new Taxes_Proxy_Behavior();
		$request  = new WP_REST_Request();
		$request->set_query_params( array( 'include' => '7,3' ) );

		$forwarded = $behavior->forwarded_params( $request->get_query_params(), $request );
		$sql       = $behavior->around(
			static function () use ( $wpdb ): string {
				return apply_filters( 'query', "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_id" );
			}
		);

		$this->assertArrayNotHasKey( 'include', $forwarded );
		$this->assertSame( array( 7, 3 ), $forwarded['wcpos_include'] );
		$this->assertStringContainsString( "{$wpdb->prefix}woocommerce_tax_rates.tax_rate_id IN (7,3)", $sql );
	}

	/**
	 * Order params use the Collection Rules plan and order rows retain v2 augmentation.
	 */
	public function test_order_behavior_moves_params_and_post_processing(): void {
		$order     = OrderHelper::create_order();
		$behavior  = new Orders_Proxy_Behavior();
		$request   = new WP_REST_Request();
		$input     = array( 'pos_cashier' => 11, 'dp' => '2' );
		$request->set_query_params( $input );
		$forwarded = $behavior->forwarded_params( $input, $request );
		$data      = $behavior->post_process( array( array( 'id' => $order->get_id() ) ) );

		$this->assertArrayNotHasKey( 'pos_cashier', $forwarded );
		$this->assertSame( '6', $forwarded['dp'] );
		$this->assertArrayHasKey( 'tax_ids', $data[0] );
		$this->assertArrayHasKey( 'links', $data[0] );
	}

	/**
	 * Product visibility is installed for one forward and removed afterward.
	 */
	public function test_product_behavior_scopes_visibility_filter(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( 17 ) ),
					),
				),
			)
		);

		// WC_Brands also hooks this filter and expects ( $args, $request ),
		// so both applications must pass the request through.
		$request  = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$behavior = new Products_Proxy_Behavior();
		$filtered = $behavior->around(
			static function () use ( $request ): array {
				return apply_filters( 'woocommerce_rest_product_object_query', array( 'post__in' => array( 17, 18 ) ), $request );
			}
		);

		$this->assertSame( array( 18 ), $filtered['post__in'] );
		$this->assertSame(
			array( 17, 18 ),
			apply_filters( 'woocommerce_rest_product_object_query', array( 'post__in' => array( 17, 18 ) ), $request )['post__in']
		);
	}

	/**
	 * Coupon permission relaxation is scoped to the proxied read.
	 */
	public function test_coupon_behavior_scopes_read_permission_filter(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
		$behavior = new Coupons_Proxy_Behavior();

		$inside = $behavior->around(
			static function (): bool {
				return apply_filters( 'woocommerce_rest_check_permissions', false, 'read', 0, 'shop_coupon' );
			}
		);

		$this->assertTrue( $inside );
		$this->assertFalse( apply_filters( 'woocommerce_rest_check_permissions', false, 'read', 0, 'shop_coupon' ) );
	}
}
