<?php
/**
 * Tests for the v2 catalog proxy product read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WC_REST_Products_Controller;
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
	 * A product row is WooCommerce's PRODUCT shape, with no WCPOS field on top.
	 *
	 * The expectation is DERIVED from WooCommerce's own products schema rather
	 * than copied out of a response; see
	 * {@see WCPOS_REST_Unit_Test_Case::view_context_fields()} for why a copied
	 * list can only ever ratify whatever we happened to emit that day. This pin
	 * used to be a 70-entry literal, which is the shape of the problem: it could
	 * not tell a field WooCommerce declares from a field we happened to serve,
	 * so it read as agreement with wc/v3 while asserting nothing of the kind
	 * (#1712).
	 */
	public function test_product_row_is_the_woocommerce_product_shape(): void {
		$product = ProductHelper::create_simple_product();

		$rows = $this->read( array( 'include' => array( $product->get_id() ) ) );
		$row  = $rows[0];

		/*
		 * The delta is `_links` alone, and that is the load-bearing claim here: on
		 * the product lane WCPOS adds NO top-level key of its own. The POS identity
		 * rides `meta_data` — a key WooCommerce itself declares — so
		 * `Sync\Proxy_Uuid_Stamper` injects `_woocommerce_pos_uuid` into an existing
		 * field rather than widening the row (pinned by the uuid tests above).
		 * `_links` is appended by `rest_get_server()->response_to_data()` from the
		 * controller's own `prepare_links()`, not by the schema.
		 */
		$this->assertEqualsCanonicalizing(
			array_merge(
				$this->view_context_fields( ( new WC_REST_Products_Controller() )->get_public_item_schema()['properties'] ),
				array( '_links' )
			),
			array_keys( $row )
		);
		/*
		 * Implied by the equality above, kept because it names the divergence this
		 * test exists to hold: v1 aliases the POS barcode onto a top-level `barcode`
		 * field (API\V1\Products_Controller::wcpos_product_response), and v2
		 * deliberately does not — the client reads the configured barcode meta
		 * instead. A regression that re-adds it should say "barcode came back", not
		 * hand a reviewer an anonymous set difference.
		 */
		$this->assertArrayNotHasKey( 'barcode', $row );
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
