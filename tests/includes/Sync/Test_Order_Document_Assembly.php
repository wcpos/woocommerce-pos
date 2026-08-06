<?php
/**
 * Characterization tests for the v2 order-document wire shape.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting, Generic.Files.OneObjectStructurePerFile -- Characterization suite documents lane divergence in inline comments.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WCPOS\WooCommercePOS\API\V2\Catalog_Proxy_Controller;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_REST_Request;

/**
 * The minimum mutation-store surface Write_Controller touches on an order update.
 *
 * Kept deliberately dumb: the point of this suite is the wc/v3 round trip the
 * controller performs (forward + document_for re-read), not the journal.
 */
final class Assembly_Fake_Mutation_Store {
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
 * Pins the CURRENT wire output of the three v2 order lanes side by side.
 *
 * The v2 surface shapes an order document three times over:
 *
 *  - PULL      Order_Serializer::serialize_order — prepare_object_for_response,
 *              augment_order_payload, add_pos_links, `woocommerce_pos_sync_serialized_order`.
 *  - PROXY     Catalog_Proxy_Controller::proxy — `woocommerce_pos_sync_proxy_response`,
 *              augment_order_payload, add_pos_links. No serialized_order filter.
 *  - WRITE-ACK Write_Controller::document_for + respond — Meta_Normalizer::normalize,
 *              add_pos_links, and tax_ids bolted on inside respond(). No item uuids,
 *              no image.id int cast.
 *
 * These tests encode TODAY's output, divergence included, so the unification can be
 * proved to move only what it means to move. Every assertion that records a KNOWN
 * DIVERGENCE is labelled as such.
 *
 * The v1 lane (Orders_Controller::wcpos_order_response) is FROZEN and deliberately
 * out of scope here — it has a different wire shape and deployed clients depend on it.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Serializer
 */
class Test_Order_Document_Assembly extends Sync_REST_Store_Test_Case {
	private const MUTATION_ID   = 'd4e5f6a7-2222-4333-8444-555566667777';
	private const ITEM_META_KEY = '_woocommerce_pos_data';

	/** @var int */
	private $attachment_id = 0;

	public function setUp(): void {
		parent::setUp();
		// Production wires these in Init; the lanes are only comparable with the
		// shared meta normalization in place on every one of them.
		Meta_Normalizer::register_hooks();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tearDown(): void {
		Meta_Normalizer::unregister_hooks();
		delete_option( 'woocommerce_pos_sync_legacy_revision_grace' );
		parent::tearDown();
	}

	/**
	 * The POS checkout-route payment link, as add_payment_link() builds it.
	 */
	private function wcpos_expected_payment_link( WC_Order $order ): string {
		return add_query_arg(
			array(
				'pay_for_order' => true,
				'key'           => $order->get_order_key(),
			),
			wcpos_checkout_url( 'order-pay/' . $order->get_id() )
		);
	}

	/**
	 * The POS receipt link, as add_receipt_link() builds it.
	 */
	private function wcpos_expected_receipt_link( WC_Order $order ): string {
		return add_query_arg(
			array( 'key' => $order->get_order_key() ),
			wcpos_checkout_url( 'wcpos-receipt/' . $order->get_id() )
		);
	}

	/**
	 * A representative order: a line item carrying structured POS meta, a product
	 * image, order taxes, and a POS uuid on the order itself.
	 */
	private function representative_order(): WC_Order {
		$product             = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price'         => 18,
			)
		);
		$this->attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg', $product->get_id() );
		$product->set_image_id( $this->attachment_id );
		$product->save();

		$order = OrderHelper::create_order( array( 'product' => $product ) );

		$items = $order->get_items( 'line_item' );
		$item  = reset( $items );
		$item->add_meta_data(
			self::ITEM_META_KEY,
			(string) wp_json_encode(
				array(
					'price'         => 18,
					'regular_price' => 18,
					'tax_status'    => 'taxable',
				)
			),
			true
		);
		$item->save();

		( new Tax_Id_Writer() )->write_for_order(
			$order,
			array(
				array(
					'type'    => 'eu_vat',
					'value'   => 'ESB12345678',
					'country' => 'ES',
				),
			)
		);

		$order->save();
		$order->calculate_taxes();
		$order->calculate_totals( false );
		$order->save();

		Pos_Uuid::ensure_uuid( $order );

		return wc_get_order( $order->get_id() );
	}

	private function pull_document( WC_Order $order ): array {
		return ( new Order_Serializer() )->serialize_order( $order->get_id(), new WP_REST_Request() );
	}

	private function proxy_document( WC_Order $order ): array {
		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_query_params( array( 'include' => (string) $order->get_id() ) );
		$rows = ( new Catalog_Proxy_Controller() )->proxy( $request, '/wc/v3/orders', 'orders' )->get_data();

		foreach ( (array) $rows as $row ) {
			if ( $order->get_id() === (int) ( $row['id'] ?? 0 ) ) {
				return $row;
			}
		}

		$this->fail( 'The orders proxy did not serve the fixture order.' );
	}

	/**
	 * Drive a real order update through Write_Controller and return the success
	 * envelope. No wc/v3 interception: the forward and the document_for re-read
	 * both hit real WooCommerce, so the ack is the genuine wire shape.
	 *
	 * @return array{document: array, currentRevision: string}
	 */
	private function write_ack( WC_Order $order, string $record_uuid ): array {
		$store          = new Assembly_Fake_Mutation_Store();
		$store->resolve = $order->get_id();

		$base_revision = Order_Serializer::canonical_revision( $this->current_bare_document( $order ) );

		$request = new WP_REST_Request( 'POST', '' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'collection'   => 'orders',
					'mutationId'   => self::MUTATION_ID,
					'operation'    => 'update',
					'recordId'     => $record_uuid,
					'baseRevision' => $base_revision,
					'payload'      => array( 'customer_note' => 'assembly characterization' ),
				)
			)
		);

		$response = ( new Write_Controller( $store ) )->push( $request );
		$this->assertSame( 200, $response->get_status(), 'The characterization push must succeed: ' . wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * The bare current-state document the write path hashes: exactly what
	 * Write_Controller::document_for produces today.
	 */
	private function current_bare_document( WC_Order $order ): array {
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );
		$request->set_param( 'dp', '6' );
		$data = rest_do_request( $request )->get_data();
		$data = Meta_Normalizer::normalize( $data );

		return Order_Serializer::add_pos_links( $data, $order );
	}

	private function item_uuid_from_meta( array $item ): ?string {
		foreach ( $item['meta_data'] ?? array() as $entry ) {
			$key = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? $entry->key : null );
			if ( Pos_Uuid::META_KEY === $key ) {
				return (string) ( is_array( $entry ) ? $entry['value'] : $entry->value );
			}
		}

		return null;
	}

	private function meta_keys( array $item ): array {
		$keys = array();
		foreach ( $item['meta_data'] ?? array() as $entry ) {
			$keys[] = (string) ( is_array( $entry ) ? ( $entry['key'] ?? '' ) : ( is_object( $entry ) ? $entry->key : '' ) );
		}

		return $keys;
	}

	/**
	 * PULL LANE — the reference shape every other v2 lane is being unified onto.
	 */
	public function test_pull_document_carries_the_full_v2_order_shape(): void {
		$order = $this->representative_order();

		$document = $this->pull_document( $order );

		// Stock wc/v3 fields survive.
		$this->assertSame( $order->get_id(), $document['id'] );
		$this->assertArrayHasKey( 'line_items', $document );
		$this->assertNotEmpty( $document['line_items'] );

		// tax_ids — the v1-owned read decoration wc/v3 never serializes.
		$this->assertSame( ( new Tax_Id_Reader() )->read_for_order( $order ), $document['tax_ids'] );

		// POS links.
		$this->assertSame( $this->wcpos_expected_payment_link( $order ), $document['links']['payment'][0]['href'] );
		$this->assertSame( $this->wcpos_expected_receipt_link( $order ), $document['links']['receipt'][0]['href'] );

		// image.id is int-cast (v1 parity); bare wc/v3 serves it as a string.
		$this->assertIsInt( $document['line_items'][0]['image']['id'] );
		$this->assertSame( $this->attachment_id, $document['line_items'][0]['image']['id'] );

		// Read-time item uuid stamping.
		$this->assertNotNull( $this->item_uuid_from_meta( $document['line_items'][0] ) );
		$this->assertTrue( Pos_Uuid::is_uuid( (string) $this->item_uuid_from_meta( $document['line_items'][0] ) ) );

		// Structured line-item meta is preserved alongside the stamped uuid.
		$this->assertContains( self::ITEM_META_KEY, $this->meta_keys( $document['line_items'][0] ) );

		// Taxes survive the assembly.
		$this->assertArrayHasKey( 'taxes', $document['line_items'][0] );
	}

	/**
	 * PROXY LANE — already unified with pull for the three augmentations; the only
	 * intentional difference is that the proxy does NOT run the serialized_order filter.
	 */
	public function test_proxy_document_matches_the_pull_shape(): void {
		$order = $this->representative_order();

		$document = $this->proxy_document( $order );

		$this->assertSame( ( new Tax_Id_Reader() )->read_for_order( $order ), $document['tax_ids'] );
		$this->assertSame( $this->wcpos_expected_payment_link( $order ), $document['links']['payment'][0]['href'] );
		$this->assertSame( $this->wcpos_expected_receipt_link( $order ), $document['links']['receipt'][0]['href'] );
		$this->assertIsInt( $document['line_items'][0]['image']['id'] );
		$this->assertSame( $this->attachment_id, $document['line_items'][0]['image']['id'] );
		$this->assertNotNull( $this->item_uuid_from_meta( $document['line_items'][0] ) );
		$this->assertContains( self::ITEM_META_KEY, $this->meta_keys( $document['line_items'][0] ) );
	}

	/**
	 * Pull and proxy agree on the canonical revision for the same order state —
	 * the invariant that lets a client move between the two lanes.
	 */
	public function test_pull_and_proxy_documents_share_a_canonical_revision(): void {
		$order = $this->representative_order();

		$this->assertSame(
			Order_Serializer::canonical_revision( $this->pull_document( $order ) ),
			Order_Serializer::canonical_revision( $this->proxy_document( $order ) )
		);
	}

	/**
	 * WRITE-ACK LANE — the diverging one.
	 *
	 * What the ack has today: links, tax_ids, normalized meta, the record uuid.
	 * What it LACKS today: line-item uuids and the image.id int cast — commit
	 * 6fa92554 unified "both v2 read lanes" and skipped the ack.
	 */
	public function test_write_ack_document_carries_links_and_tax_ids(): void {
		$order = $this->representative_order();
		$uuid  = Pos_Uuid::ensure_uuid( $order );

		$document = $this->write_ack( $order, $uuid )['document'];

		$this->assertSame( $order->get_id(), $document['id'] );
		$this->assertSame( ( new Tax_Id_Reader() )->read_for_order( $order ), $document['tax_ids'] );
		$this->assertSame( $this->wcpos_expected_payment_link( $order ), $document['links']['payment'][0]['href'] );
		$this->assertSame( $this->wcpos_expected_receipt_link( $order ), $document['links']['receipt'][0]['href'] );

		// The record uuid is mirrored into the document for client reconciliation.
		$this->assertSame( $uuid, Pos_Uuid::read_valid_uuid_from_meta( $document['meta_data'] ?? array() ) );
	}

	/**
	 * KNOWN DIVERGENCE (about to be removed deliberately): the write-ack document
	 * carries NO line-item uuids, and serves image.id as the bare wc/v3 string.
	 *
	 * A client that adopts the ack document verbatim therefore loses the item
	 * identity it had from the last pull until the next read re-supplies it.
	 */
	public function test_write_ack_document_lacks_item_uuids_and_image_cast(): void {
		$order = $this->representative_order();
		$uuid  = Pos_Uuid::ensure_uuid( $order );

		$document = $this->write_ack( $order, $uuid )['document'];

		// DIVERGENCE 1 — no read-time item uuid stamping on the ack.
		$this->assertNull( $this->item_uuid_from_meta( $document['line_items'][0] ) );

		// DIVERGENCE 2 — image.id is the bare wc/v3 string, not the v1-parity int.
		$this->assertIsString( $document['line_items'][0]['image']['id'] );
	}

	/**
	 * The ack's currentRevision is the canonical revision — the same value the next
	 * pull of unchanged state produces. This is the contract that must survive any
	 * change to the ack's document shape.
	 */
	public function test_write_ack_revision_equals_the_next_pull_canonical_revision(): void {
		$order = $this->representative_order();
		$uuid  = Pos_Uuid::ensure_uuid( $order );

		$ack = $this->write_ack( $order, $uuid );

		$this->assertStringStartsWith( 'sha256:', $ack['currentRevision'] );
		$this->assertSame(
			Order_Serializer::canonical_revision( $this->pull_document( wc_get_order( $order->get_id() ) ) ),
			$ack['currentRevision']
		);
	}
}
