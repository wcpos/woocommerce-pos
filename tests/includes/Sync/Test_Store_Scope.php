<?php
/**
 * Store scope propagation across the v2 sync lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WCPOS\WooCommercePOS\Sync\Revision;
use WCPOS\WooCommercePOS\Sync\Store_Scope;
use WP_REST_Request;

/**
 * The till's store scope has to survive the whole v2 round trip.
 *
 * The v1 lane got `store_id` for free — the legacy http client put it on the
 * query string of every request. The v2 sync lanes use the engine's own
 * fetcher, so the scope arrives as the `X-WCPOS-Store` header and this plugin
 * is what republishes it, in v1's shape, onto each inner `wc/v3` request.
 *
 * The invariant these tests defend is not merely "the scope arrives". It is
 * that an UNKNOWN scope stays distinguishable from a global one: when the
 * header is missing or unusable, nothing is stamped, so a consumer holding
 * store-scoped data can refuse the write instead of silently overwriting the
 * global fields (pro#425).
 */
class Test_Store_Scope extends Sync_REST_Store_Test_Case {
	/**
	 * `store_id` seen by the innermost wc/v3 request, per capture.
	 *
	 * @var array<int, null|int|string>
	 */
	private $captured = array();

	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$this->captured = array();
		Store_Scope::reset();
	}

	public function tearDown(): void {
		Store_Scope::reset();
		delete_option( Api::OPTION_ENABLED );
		parent::tearDown();
	}

	/*
	 * ---------------------------------------------------------------------
	 * resolve(): what counts as a store
	 * ---------------------------------------------------------------------
	 */

	public function test_resolve_reads_a_positive_store_from_the_header(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_header( Store_Scope::HEADER, '7' );

		$this->assertSame( 7, Store_Scope::resolve( $request ) );
	}

	/**
	 * Everything that is not a post id is UNKNOWN, never a store and never
	 * global. `0` matters most: it is the client's single-store sentinel, the
	 * same one the order lane tests before stamping `_pos_store`.
	 *
	 * @dataProvider provide_unusable_scopes
	 *
	 * @param string $header The raw header value.
	 */
	public function test_resolve_rejects_an_unusable_scope( string $header ): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_header( Store_Scope::HEADER, $header );

		$this->assertNull( Store_Scope::resolve( $request ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public function provide_unusable_scopes(): array {
		return array(
			'single-store sentinel' => array( '0' ),
			'padded sentinel'       => array( ' 0 ' ),
			'blank'                 => array( '' ),
			'whitespace'            => array( '   ' ),
			'negative'              => array( '-3' ),
			'decimal'               => array( '1.5' ),
			'non-numeric'           => array( 'store-1' ),
			'numeric with suffix'   => array( '7x' ),
		);
	}

	public function test_resolve_falls_back_to_the_v1_store_id_param(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'store_id', '9' );

		$this->assertSame( 9, Store_Scope::resolve( $request ) );
	}

	/**
	 * @dataProvider provide_unusable_scopes
	 *
	 * @param string $header The raw header value.
	 */
	public function test_resolve_does_not_fall_back_to_the_store_id_param_for_v2( string $header ): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_header( Store_Scope::HEADER, $header );
		$request->set_param( 'store_id', '9' );

		$this->assertNull( Store_Scope::resolve( $request ) );
	}

	public function test_resolve_does_not_fall_back_to_the_store_id_param_when_v2_header_is_absent(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_param( 'store_id', '9' );

		$this->assertNull( Store_Scope::resolve( $request ) );
	}

	public function test_resolve_prefers_the_header_over_the_param(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
		$request->set_param( 'store_id', '9' );
		$request->set_header( Store_Scope::HEADER, '7' );

		$this->assertSame( 7, Store_Scope::resolve( $request ) );
	}

	public function test_resolve_ignores_an_absent_header_and_param(): void {
		$this->assertNull( Store_Scope::resolve( new WP_REST_Request( 'GET', '/wcpos/v2/products' ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * stamp(): republishing the scope in v1's shape
	 * ---------------------------------------------------------------------
	 */

	public function test_stamp_republishes_the_scope_as_the_v1_param(): void {
		Store_Scope::set_current( 7 );
		$inner = Store_Scope::stamp( new WP_REST_Request( 'GET', '/wc/v3/products' ) );

		$this->assertSame( 7, $inner->get_param( 'store_id' ) );
	}

	/**
	 * An unknown scope must leave the inner request looking exactly like an
	 * unscoped v1 request. Stamping a placeholder here is the bug: downstream
	 * could not then tell "no store" from "the global store".
	 */
	public function test_stamp_leaves_an_unscoped_request_untouched(): void {
		Store_Scope::set_current( null );
		$inner = Store_Scope::stamp( new WP_REST_Request( 'GET', '/wc/v3/products' ) );

		$this->assertNull( $inner->get_param( 'store_id' ) );
		$this->assertArrayNotHasKey( 'store_id', $inner->get_params() );
	}

	public function test_stamp_never_overrides_a_scope_the_caller_set(): void {
		Store_Scope::set_current( 7 );
		$inner = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$inner->set_param( 'store_id', 9 );

		$this->assertSame( 9, Store_Scope::stamp( $inner )->get_param( 'store_id' ) );
	}

	public function test_set_current_normalizes_the_single_store_sentinel_to_unknown(): void {
		Store_Scope::set_current( 0 );

		$this->assertNull( Store_Scope::current() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The round trip
	 * ---------------------------------------------------------------------
	 */

	public function test_a_scoped_v2_request_latches_the_scope_for_the_dispatch(): void {
		$captured = null;
		add_filter(
			'woocommerce_rest_prepare_product_object',
			function ( $response ) use ( &$captured ) {
				$captured = Store_Scope::current();

				return $response;
			}
		);

		ProductHelper::create_simple_product();
		$this->read_catalog( 7 );

		$this->assertSame( 7, $captured );
	}

	/**
	 * The greedy catalog list pull — what decides whether the products grid
	 * shows this store's prices or the web store's.
	 */
	public function test_the_catalog_proxy_forwards_the_scope_to_the_inner_wc_v3_read(): void {
		$this->capture_product_scope();
		ProductHelper::create_simple_product();

		$this->read_catalog( 7 );

		$this->assertSame( array( 7 ), array_unique( $this->captured ) );
	}

	public function test_the_catalog_proxy_forwards_no_scope_when_the_till_sends_none(): void {
		$this->capture_product_scope();
		ProductHelper::create_simple_product();

		$this->read_catalog( null );

		$this->assertSame( array( null ), array_unique( $this->captured ) );
	}

	/**
	 * The write forward — the half that decides WHICH store's price a cashier's
	 * edit lands on.
	 */
	public function test_a_product_push_forwards_the_scope_to_the_inner_wc_v3_write(): void {
		$scopes  = array();
		add_filter(
			'woocommerce_rest_pre_insert_product_object',
			function ( $product, $request ) use ( &$scopes ) {
				$scopes[] = $request->get_param( 'store_id' );

				return $product;
			},
			10,
			2
		);

		$this->push_price( $this->uuid_product(), 7 );

		$this->assertSame( array( 7 ), $scopes );
	}

	public function test_a_product_push_forwards_no_scope_when_the_till_sends_none(): void {
		$scopes = array();
		add_filter(
			'woocommerce_rest_pre_insert_product_object',
			function ( $product, $request ) use ( &$scopes ) {
				$scopes[] = $request->get_param( 'store_id' );

				return $product;
			},
			10,
			2
		);

		$this->push_price( $this->uuid_product(), null );

		$this->assertSame( array( null ), $scopes );
	}

	/**
	 * The write acknowledgement is what the client stores. If the read-back
	 * loses the scope the till redisplays the global price moments after the
	 * cashier changed the store's — the second half of the pro#425 damage.
	 */
	public function test_the_write_acknowledgement_is_read_back_under_the_scope(): void {
		$this->capture_product_scope();

		$this->push_price( $this->uuid_product(), 7 );

		$this->assertNotEmpty( $this->captured, 'the ack never serialized a product' );
		$this->assertSame( array( 7 ), array_unique( $this->captured ) );
	}

	/**
	 * Every per-object read lane — changes, resolve, targeted variations, the
	 * ack — hydrates through Product_Serializer, so the scope has to reach the
	 * serializer even though its callers hand it a bare `GET /`.
	 */
	public function test_the_product_serializer_carries_the_scope_into_serialization(): void {
		$this->capture_product_scope();
		Store_Scope::set_current( 7 );

		( new Product_Serializer() )->serialize( ProductHelper::create_simple_product() );

		$this->assertSame( array( 7 ), $this->captured );
	}

	public function test_the_product_serializer_carries_no_scope_when_unscoped(): void {
		$this->capture_product_scope();
		Store_Scope::set_current( null );

		( new Product_Serializer() )->serialize( ProductHelper::create_simple_product() );

		$this->assertSame( array( null ), $this->captured );
	}

	public function test_the_store_scope_header_survives_cors_preflight(): void {
		$this->assertContains( Store_Scope::HEADER, apply_filters( 'rest_allowed_cors_headers', array() ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Record the `store_id` each inner product serialization is scoped to.
	 */
	private function capture_product_scope(): void {
		add_filter(
			'woocommerce_rest_prepare_product_object',
			function ( $response, $product, $request ) {
				$this->captured[] = $request->get_param( 'store_id' );

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * Dispatch a catalog proxy product read under the given scope.
	 *
	 * @param null|int $store_id The store the till is scoped to, or null.
	 */
	private function read_catalog( ?int $store_id ): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		if ( null !== $store_id ) {
			$request->set_header( Store_Scope::HEADER, (string) $store_id );
		}

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A product the push lane can resolve by uuid.
	 *
	 * @return \WC_Product
	 */
	private function uuid_product() {
		$product = ProductHelper::create_simple_product();
		update_post_meta( $product->get_id(), Pos_Uuid::META_KEY, wp_generate_uuid4() );

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Push a price edit for a product under the given scope.
	 *
	 * @param \WC_Product $product  The product to edit.
	 * @param null|int    $store_id The store the till is scoped to, or null.
	 */
	private function push_price( $product, ?int $store_id ): void {
		$uuid     = (string) get_post_meta( $product->get_id(), Pos_Uuid::META_KEY, true );
		$revision = $this->current_revision( $product->get_id() );
		// The revision probe above is a plain unscoped wc/v3 read and serializes
		// the product too — drop it so a capture only ever holds the push itself.
		$this->captured = array();

		$request = $this->wp_rest_post_request( '/wcpos/v2/push/products' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $store_id ) {
			$request->set_header( Store_Scope::HEADER, (string) $store_id );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'mutationId'   => wp_generate_uuid4(),
					'operation'    => 'update',
					'collection'   => 'products',
					'recordId'     => $uuid,
					'baseRevision' => $revision,
					'payload'      => array( 'regular_price' => '25.00' ),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * The revision the push lane will compare against.
	 *
	 * @param int $id Product id.
	 */
	private function current_revision( int $id ): string {
		$read = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $id ) );

		return Revision::compute( Meta_Normalizer::normalize( (array) $read->get_data() ) );
	}
}
