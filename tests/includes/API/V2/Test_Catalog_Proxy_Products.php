<?php
/**
 * Tests for the v2 catalog proxy product read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Product rows preserve the replication contract exposed by wcpos/v2.
 */
class Test_Catalog_Proxy_Products extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes and the read-time identity stamper.
	 */
	public function setUp(): void {
		Proxy_Uuid_Stamper::register_proxy_stampers();
		parent::setUp();
	}

	/**
	 * Remove sync state written outside the test transaction.
	 */
	public function tearDown(): void {
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
	}

	/**
	 * Dispatch a product collection request.
	 *
	 * @param array $params Query parameters.
	 */
	private function read( array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Decimal-enabled catalog reads must not integer-coerce product stock.
	 */
	public function test_decimal_stock_quantity_is_preserved_on_product_read(): void {
		$this->setup_decimal_quantity_tests();
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 1.5,
			)
		);

		$rows = $this->read( array( 'include' => array( $product->get_id() ) ) );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 1.5, $rows[0]['stock_quantity'] );
	}

	/**
	 * A duplicate proxy UUID is re-keyed before either row reaches the client.
	 */
	public function test_product_uuid_collision_rekeys_one_record_and_serves_distinct_uuids(): void {
		$shared = wp_generate_uuid4();
		$first  = ProductHelper::create_simple_product();
		$second = ProductHelper::create_simple_product();
		update_post_meta( $first->get_id(), Pos_Uuid::META_KEY, $shared );
		update_post_meta( $second->get_id(), Pos_Uuid::META_KEY, $shared );
		$ids = array( $first->get_id(), $second->get_id() );

		$rows = array_column( $this->read( array( 'include' => $ids ) ), null, 'id' );
		$served = array();
		foreach ( $ids as $id ) {
			$meta          = array_column( $rows[ $id ]['meta_data'], 'value', 'key' );
			$served[ $id ] = $meta[ Pos_Uuid::META_KEY ];
			$this->assertTrue( Uuid::isValid( $served[ $id ] ) );
		}
		$stored = array(
			$first->get_id()  => get_post_meta( $first->get_id(), Pos_Uuid::META_KEY, true ),
			$second->get_id() => get_post_meta( $second->get_id(), Pos_Uuid::META_KEY, true ),
		);

		$this->assertCount( 2, array_unique( $served ) );
		$this->assertCount( 2, array_unique( $stored ) );
		$this->assertContains( $shared, $stored );
		$this->assertSame( $stored, $served );
	}

	/**
	 * Product rows expose the complete v2 field set without the legacy barcode alias.
	 */
	public function test_product_row_has_full_v2_field_set(): void {
		$product = ProductHelper::create_simple_product();

		$rows = $this->read( array( 'include' => array( $product->get_id() ) ) );
		$row  = $rows[0];

		$this->assertEqualsCanonicalizing(
			array(
				'id',
				'name',
				'slug',
				'permalink',
				'date_created',
				'date_created_gmt',
				'date_modified',
				'date_modified_gmt',
				'type',
				'status',
				'featured',
				'catalog_visibility',
				'description',
				'short_description',
				'sku',
				'global_unique_id',
				'price',
				'regular_price',
				'sale_price',
				'date_on_sale_from',
				'date_on_sale_from_gmt',
				'date_on_sale_to',
				'date_on_sale_to_gmt',
				'price_html',
				'on_sale',
				'purchasable',
				'total_sales',
				'virtual',
				'downloadable',
				'downloads',
				'download_limit',
				'download_expiry',
				'external_url',
				'button_text',
				'tax_status',
				'tax_class',
				'manage_stock',
				'stock_quantity',
				'stock_status',
				'backorders',
				'backorders_allowed',
				'backordered',
				'low_stock_amount',
				'sold_individually',
				'weight',
				'dimensions',
				'shipping_required',
				'shipping_taxable',
				'shipping_class',
				'shipping_class_id',
				'reviews_allowed',
				'post_password',
				'average_rating',
				'rating_count',
				'related_ids',
				'upsell_ids',
				'cross_sell_ids',
				'parent_id',
				'purchase_note',
				'categories',
				'brands',
				'tags',
				'images',
				'has_options',
				'attributes',
				'default_attributes',
				'variations',
				'grouped_products',
				'menu_order',
				'meta_data',
				'_links',
			),
			array_keys( $row )
		);
		$this->assertArrayNotHasKey( 'barcode', $row );
		$this->assertArrayHasKey( 'sku', $row );
		$this->assertArrayHasKey( 'global_unique_id', $row );
	}

	/**
	 * Product search deliberately does not widen title search to SKU search.
	 */
	public function test_search_does_not_match_product_sku(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_name( 'V2 Product Search Boundary' );
		$product->set_sku( 'SKU-ONLY-NEEDLE-1456' );
		$product->save();

		$rows = $this->read( array( 'search' => 'NEEDLE-1456' ) );

		// Barcode lookup belongs to /wcpos/v2/resolve/barcode; broader catalog
		// filtering is client-side by design, not a server-side product search mode.
		$this->assertSame( array(), $rows );
	}
}
