<?php
/**
 * Tests for the schema-scoped order revision recipe and the pull checkpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting, Generic.Files.OneObjectStructurePerFile -- Suite-local fake store, documented inline.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Order;
use WCPOS\WooCommercePOS\API\V2\Catalog_Proxy_Controller;
use WCPOS\WooCommercePOS\API\V2\Orders_Controller;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;

/**
 * The minimum mutation-store surface Write_Controller touches on an order update
 * that stops at the CAS precondition. Deliberately dumb: this suite is about the
 * revision, not the journal.
 */
final class Recipe_Fake_Mutation_Store {
	/** @var int */
	public $resolve = 0;

	/** @var array<string,array> */
	public $lookups = array();

	public function lookup( string $collection, string $mutation_id ): ?array {
		return $this->lookups[ $mutation_id ] ?? null;
	}

	public function reserve( string $collection, string $mutation_id, string $record_uuid, string $operation, string $fingerprint = '' ): bool {
		if ( isset( $this->lookups[ $mutation_id ] ) ) {
			return false;
		}
		$this->lookups[ $mutation_id ] = array(
			'collection'  => $collection,
			'record_uuid' => $record_uuid,
			'operation'   => $operation,
			'fingerprint' => $fingerprint,
			'remote_id'   => 0,
			'status'      => 'pending',
		);

		return true;
	}

	public function acquire_record_lock( string $collection, string $uuid ): bool {
		return true;
	}

	public function release_record_lock( string $collection, string $uuid ): void {}

	public function resolve_id_by_uuid( string $id_type, string $uuid, array $opts = array() ) {
		return $this->resolve;
	}

	public function persist_uuid( string $id_type, int $id, string $uuid ): bool {
		$this->resolve = $id;

		return true;
	}

	public function persist_order_audit_meta( int $id, array $meta, string $created_via = '' ): void {}

	public function mark_applied( string $mutation_id, int $remote_id, int $response_status ): bool {
		return true;
	}

	public function mark_poison( string $mutation_id, int $remote_id, int $response_status = 201 ): bool {
		return true;
	}

	public function mark_indeterminate( string $mutation_id, int $remote_id, int $response_status ): bool {
		return true;
	}

	public function finalize( string $mutation_id, int $remote_id ): bool {
		return true;
	}

	public function finalize_poison( string $mutation_id, int $remote_id ): bool {
		return true;
	}

	public function release( string $mutation_id ): void {}

	public function reclaim_stale( string $mutation_id, int $ttl ): bool {
		return false;
	}

	public function reservation_ttl(): int {
		return 900;
	}
}

/**
 * Pins THE order revision recipe (free#1870, ADR 0033) and the unified pull
 * checkpoint envelope.
 *
 * Every assertion here is about coherence: the three sites that compute an order
 * revision — the pull-time compute, the CAS re-read behind a push, and the proxy
 * `_rxdb_revision` stamp — must hash identical bytes for one unchanged order, and
 * only a CONTENT change may move them.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Serializer
 * @covers \WCPOS\WooCommercePOS\API\V2\Orders_Controller
 */
class Test_Order_Revision_Recipe extends Sync_REST_Store_Test_Case {
	private const MUTATION_ID = 'b1c2d3e4-9999-4888-8777-666655554444';

	/** @var string The value the site-local `x_probe` field serves. */
	private $probe_value = 'first';

	/**
	 * Production wires the shared meta normalization and the proxy revision stamp
	 * in Init; the three lanes are only comparable with both in place.
	 */
	public function setUp(): void {
		parent::setUp();
		Meta_Normalizer::register_hooks();
		Revision::register_proxy_stamps();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tearDown(): void {
		Revision::unregister_proxy_stamps();
		Meta_Normalizer::unregister_hooks();
		remove_all_filters( 'woocommerce_pos_sync_order_revision_fields' );
		parent::tearDown();
	}

	/**
	 * An order with a POS identity, ready to be read on all three lanes.
	 */
	private function fixture_order(): WC_Order {
		$order = OrderHelper::create_order();
		$order->set_status( 'processing' );
		$order->save();
		Pos_Uuid::ensure_uuid( $order );

		return wc_get_order( $order->get_id() );
	}

	/** The pull lane's revision: the served document, hashed. */
	private function pull_revision( int $order_id ): string {
		return Order_Serializer::canonical_revision(
			( new Order_Serializer() )->serialize_order( $order_id, new WP_REST_Request() )
		);
	}

	/**
	 * The CAS revision: what Write_Controller::revision_for() hashes on a push —
	 * Order_Writer::document()'s bare, normalized wc/v3 re-read plus the POS links.
	 */
	private function cas_revision( int $order_id ): string {
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id );
		$request->set_param( 'dp', '6' );
		$data = Meta_Normalizer::normalize( rest_do_request( $request )->get_data() );

		return Order_Serializer::canonical_revision(
			Order_Serializer::add_pos_links( (array) $data, wc_get_order( $order_id ) )
		);
	}

	/** The revision the proxy lane stamps onto a served order row. */
	private function proxy_revision( int $order_id ): string {
		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_query_params( array( 'include' => (string) $order_id ) );
		$rows = ( new Catalog_Proxy_Controller() )->proxy( $request, '/wc/v3/orders', 'orders' )->get_data();

		foreach ( (array) $rows as $row ) {
			if ( $order_id === (int) ( $row['id'] ?? 0 ) ) {
				return (string) $row['_rxdb_revision'];
			}
		}

		$this->fail( 'The orders proxy did not serve the fixture order.' );
	}

	/** Serve a site-local `x_probe` field on every wc/v3 order serialization. */
	private function add_site_local_field(): void {
		add_filter(
			'woocommerce_rest_prepare_shop_order_object',
			function ( $response ) {
				$data             = $response->get_data();
				$data['x_probe']  = $this->probe_value;
				$response->set_data( $data );

				return $response;
			}
		);
	}

	/** Push one order update and return the raw response. */
	private function push_update( WC_Order $order, string $uuid, string $base_revision ) {
		$store          = new Recipe_Fake_Mutation_Store();
		$store->resolve = $order->get_id();

		$request = new WP_REST_Request( 'POST', '' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'collection'   => 'orders',
					'mutationId'   => self::MUTATION_ID,
					'operation'    => 'update',
					'recordId'     => $uuid,
					'baseRevision' => $base_revision,
					'payload'      => array( 'customer_note' => 'recipe pin' ),
				)
			)
		);

		return ( new Write_Controller( $store ) )->push( $request );
	}

	/**
	 * THE free#1744 pin: the public serialized-order filter is pull-only, so its
	 * additions must not reach the hash — all three sites still agree.
	 */
	public function test_order_revision_with_a_serialized_order_filter_adding_a_key_agrees_on_all_three_sites(): void {
		// Arrange.
		$order = $this->fixture_order();
		add_filter(
			'woocommerce_pos_sync_serialized_order',
			static function ( $payload ) {
				$payload['x_third_party'] = 'read-time decoration';

				return $payload;
			}
		);

		// Act.
		$pull = $this->pull_revision( $order->get_id() );

		// Assert.
		$this->assertSame( $pull, $this->cas_revision( $order->get_id() ) );
		$this->assertSame( $pull, $this->proxy_revision( $order->get_id() ) );
	}

	/**
	 * A third-party REST field is outside WooCommerce's own order schema, so the
	 * revision cannot see it.
	 */
	public function test_order_revision_with_a_registered_rest_field_is_unchanged(): void {
		// Arrange.
		$order  = $this->fixture_order();
		$before = $this->pull_revision( $order->get_id() );

		// Act.
		register_rest_field(
			'shop_order',
			'x_rest_field',
			array(
				'get_callback' => static function () {
					return 'third-party value';
				},
				'schema'       => array( 'type' => 'string' ),
			)
		);

		// Assert.
		$this->assertSame( $before, $this->pull_revision( $order->get_id() ) );
	}

	/**
	 * The revision-fields filter is the opt-in: a site-local field named there
	 * joins the hash on every site, and an edit to it is a real conflict.
	 */
	public function test_order_revision_fields_filter_adding_a_site_local_key_moves_the_revision_and_409s_a_stale_push(): void {
		// Arrange.
		$order = $this->fixture_order();
		$uuid  = (string) $order->get_meta( Pos_Uuid::META_KEY );
		$this->add_site_local_field();
		$unscoped = $this->pull_revision( $order->get_id() );
		add_filter(
			'woocommerce_pos_sync_order_revision_fields',
			static function ( $fields ) {
				$fields[] = 'x_probe';

				return $fields;
			}
		);

		// Act.
		$scoped = $this->pull_revision( $order->get_id() );

		// Assert: the opted-in field is inside the hash, and every site sees it.
		$this->assertNotSame( $unscoped, $scoped );
		$this->assertSame( $scoped, $this->cas_revision( $order->get_id() ) );
		$this->assertSame( $scoped, $this->proxy_revision( $order->get_id() ) );

		// Act: the field changes server-side under a client holding $scoped.
		$this->probe_value = 'second';
		$response          = $this->push_update( $order, $uuid, $scoped );

		// Assert.
		$this->assertNotSame( $scoped, $this->cas_revision( $order->get_id() ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_conflict', $response->get_data()['code'] );
	}

	/**
	 * A save with no content change moves `date_modified`, and the generated dates
	 * are outside the hash precisely so that costs no one a 409. A quantity change
	 * is content and must move it.
	 */
	public function test_order_revision_ignores_a_noop_save_and_moves_on_a_line_item_quantity_change(): void {
		// Arrange.
		$order  = $this->fixture_order();
		$before = $this->pull_revision( $order->get_id() );

		// Act: a save that only moves the generated modified date.
		$order->set_date_modified( time() + 3600 );
		$order->save();

		// Assert.
		$this->assertSame( $before, $this->pull_revision( $order->get_id() ) );

		// Act: real content.
		$order = wc_get_order( $order->get_id() );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item->set_quantity( $item->get_quantity() + 1 );
			$item->save();
			break;
		}
		$order->save();

		// Assert.
		$this->assertNotSame( $before, $this->pull_revision( $order->get_id() ) );
	}

	/**
	 * Meta row ids are storage identity, not content: deleting and re-adding the
	 * same key/value mints a new meta id and must leave the revision alone, on the
	 * order and on a line item alike.
	 */
	public function test_order_revision_ignores_a_meta_row_resave_on_the_order_and_on_a_line_item(): void {
		// Arrange.
		$order = $this->fixture_order();
		$order->add_meta_data( 'wcpos_probe', 'stable', true );
		$item_id = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item->add_meta_data( 'wcpos_item_probe', 'stable', true );
			$item->save();
			$item_id = $item->get_id();
			break;
		}
		$order->save();
		$before = $this->pull_revision( $order->get_id() );

		// Act: same key, same value, new meta rows.
		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( 'wcpos_probe' );
		$order->save();
		$order->add_meta_data( 'wcpos_probe', 'stable', true );
		$order->save();

		$item = $order->get_item( $item_id );
		$item->delete_meta_data( 'wcpos_item_probe' );
		$item->save();
		$item->add_meta_data( 'wcpos_item_probe', 'stable', true );
		$item->save();

		// Assert.
		$this->assertSame( $before, $this->pull_revision( $order->get_id() ) );
	}

	/**
	 * ONE checkpoint shape: `complete`, the journal fields nested, no top-level
	 * copies — and a checkpoint on an empty page too.
	 */
	public function test_pull_on_an_empty_page_returns_the_unified_checkpoint_envelope(): void {
		// Arrange.
		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_query_params(
			array(
				'limit'          => 100,
				'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
				'order_id'       => 0,
				'sequence'       => 0,
			)
		);

		// Act.
		$data = ( new Orders_Controller() )->pull_orders( $request )->get_data();

		// Assert.
		$this->assertSame( array(), $data['documents'] );
		$this->assertTrue( $data['complete'] );
		$this->assertSame(
			array( 'documents', 'deletes', 'checkpoint', 'complete' ),
			array_keys( $data )
		);
		$this->assertSame(
			array( 'updatedAtGmt', 'orderId', 'revision', 'sequence', 'epoch', 'head', 'horizon' ),
			array_keys( $data['checkpoint'] )
		);
		$this->assertIsString( $data['checkpoint']['epoch'] );
		$this->assertSame( 0, $data['checkpoint']['horizon'] );
	}

	/**
	 * The canonical form itself: generated dates out, paid/completed dates in,
	 * everything outside WooCommerce's order schema out.
	 */
	public function test_canonical_form_keeps_only_schema_keys_without_the_generated_dates(): void {
		// Arrange.
		$payload = array(
			'total'              => '10.00',
			'x_third_party'      => 'register_rest_field noise',
			'status'             => 'processing',
			'id'                 => 42,
			'date_created'       => '2026-09-01T00:00:00',
			'date_created_gmt'   => '2026-09-01T00:00:00',
			'date_modified'      => '2026-09-02T00:00:00',
			'date_modified_gmt'  => '2026-09-02T00:00:00',
			'date_paid'          => '2026-09-01T00:10:00',
			'date_paid_gmt'      => '2026-09-01T00:10:00',
			'date_completed_gmt' => '2026-09-01T00:20:00',
		);

		// Act.
		$form = Order_Serializer::canonical_form( $payload );

		// Assert.
		$this->assertSame(
			array( 'date_completed_gmt', 'date_paid', 'date_paid_gmt', 'id', 'status', 'total' ),
			array_keys( $form )
		);
	}
}
