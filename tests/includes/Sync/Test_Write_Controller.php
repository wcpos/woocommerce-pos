<?php
/**
 * Sync write controller pipeline tests ported from the lab.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions -- Ported lab suite preserves its fake-store vocabulary and compact scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use const WCPOS\WooCommercePOS\VERSION;

/** In-memory mutation store — lets the controller's apply logic be tested without a DB. */
final class Fake_Mutation_Store {
	/** @var array<string,array> mutationId => {remote_id, operation, record_uuid, status} that lookup() returns */
	public array $lookups = array();
	/** resolve_id_by_uuid() returns this (0 = none, an int id, or a WP_Error for an ambiguous uuid) */
	public $resolve = 0;
	/** resolve_id_by_uuid() return values consumed before falling back to $resolve */
	public array $resolveResults = array();
	/** lookup() return values consumed before falling back to $lookups */
	public array $lookupResults = array();
	/** @var array[] persist_uuid() calls */
	public array $persisted = array();
	/** @var array[] persist_order_audit_meta() calls: {id, meta, created_via} */
	public array $auditMeta = array();
	/** @var string[] mutationIds reserve() was called for */
	public array $reserved = array();
	/** @var array<string,int> mutationId => remote_id finalized */
	public array $finalized = array();
	public array $applied = array();
	public array $poisoned = array();
	public array $fingerprints = array();
	public bool $finalizeOk = true;
	public bool $poisonOk = true;
	public bool $indeterminateOk = true;
	public bool $persistUuidOk = true;
	/** @var string[] mutationIds released */
	public array $released = array();
	/** consumed per reserve() call; empty ⇒ true */
	public array $reserveResults = array();
	/** what reclaim_stale() returns */
	public bool $reclaimOk = false;
	/** what acquire_record_lock() returns (false ⇒ couldn't serialise) */
	public bool $recordLockOk = true;
	/** @var string[] ordered trace of acquire/release_record_lock + apply, to assert the lock wraps apply */
	public array $lockTrace = array();

	public function lookup( string $collection, string $mutation_id ): ?array {
		if ( array() !== $this->lookupResults ) {
			return array_shift( $this->lookupResults );
		}
		return $this->lookups[ $mutation_id ] ?? null;
	}
	public function reserve( string $collection, string $mutation_id, string $record_uuid, string $operation, string $fingerprint = '' ): bool {
		$this->reserved[] = $mutation_id;
		$this->fingerprints[ $mutation_id ] = $fingerprint;
		$reserved = isset( $this->lookups[ $mutation_id ] ) ? false : ( array_shift( $this->reserveResults ) ?? true );
		if ( ! isset( $this->lookups[ $mutation_id ] ) ) {
			$this->lookups[ $mutation_id ] = array(
				'collection' => $collection,
				'remote_id' => 0,
				'operation' => $operation,
				'record_uuid' => $record_uuid,
				'status' => 'pending',
				'fingerprint' => $fingerprint,
			);
		}
		return $reserved;
	}
	public function mark_applied( string $mutation_id, int $remote_id, int $response_status ): bool {
		$this->applied[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['remote_id'] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'applied';
		$this->lookups[ $mutation_id ]['response_status'] = $response_status;
		return true;
	}
	public function mark_poison( string $mutation_id, int $remote_id, int $response_status = 201 ): bool {
		if ( ! $this->poisonOk ) {
			return false;
		}
		$this->poisoned[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['remote_id'] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'poison';
		$this->lookups[ $mutation_id ]['response_status'] = $response_status;
		return true;
	}
	public function mark_indeterminate( string $mutation_id, int $remote_id, int $response_status ): bool {
		if ( ! $this->indeterminateOk ) {
			return false;
		}
		$this->lookups[ $mutation_id ]['remote_id'] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'blocked';
		$this->lookups[ $mutation_id ]['response_status'] = $response_status;
		return true;
	}
	public function finalize( string $mutation_id, int $remote_id ): bool {
		if ( ! $this->finalizeOk ) {
			return false;
		}
		$this->finalized[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'done';
		return true;
	}
	public function finalize_poison( string $mutation_id, int $remote_id ): bool {
		if ( ! $this->finalizeOk ) {
			$this->lookups[ $mutation_id ]['status'] = 'poison';
			return false;
		}
		$this->finalized[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'done';
		return true;
	}
	public function release( string $mutation_id ): void {
		$this->released[] = $mutation_id;
		if ( 'pending' === ( $this->lookups[ $mutation_id ]['status'] ?? '' ) ) {
			unset( $this->lookups[ $mutation_id ] );
		}
	}
	public function reclaim_stale( string $mutation_id, int $ttl ): bool {
		if ( 'create' === ( $this->lookups[ $mutation_id ]['operation'] ?? '' ) ) {
			return false;
		}
		if ( $this->reclaimOk ) {
			unset( $this->lookups[ $mutation_id ] );
			return true;
		}
		return false;
	}
	public function reservation_ttl(): int {
		return 900;
	}
	public function acquire_record_lock( string $collection, string $uuid ): bool {
		$this->lockTrace[] = 'acquire';
		return $this->recordLockOk;
	}
	public function release_record_lock( string $collection, string $uuid ): void {
		$this->lockTrace[] = 'release';
	}
	public function resolve_id_by_uuid( string $id_type, string $uuid, array $opts = array() ) {
		$this->lockTrace[] = 'resolve'; // runs INSIDE apply → must fall between acquire and release
		if ( ! empty( $this->resolveResults ) ) {
			return array_shift( $this->resolveResults );
		}
		return $this->resolve;
	}
	public function persist_uuid( string $id_type, int $id, string $uuid ): bool {
		$this->persisted[] = compact( 'id_type', 'id', 'uuid' );
		if ( $this->persistUuidOk ) {
			$this->resolve = $id;
		}
		return $this->persistUuidOk;
	}
	public function persist_order_audit_meta( int $id, array $meta, string $created_via = '' ): void {
		$this->auditMeta[] = compact( 'id', 'meta', 'created_via' );
	}
}

final class Test_Write_Controller extends WP_UnitTestCase {
	public const REC = '5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c6d';
	private const MID = 'a1b2c3d4-1111-4222-8333-444455556666';
	private const DEFAULT_FINGERPRINT = 'e3e6ca70cef8d8720aeedb9e7df682b8a4349d76774d86682e0943ed87475585';
	private const ORDER_FINGERPRINT = '987129ae825778cabd9640deb6b3d92fe5b6e3f89ff58e7fd69340587718c62b';

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wcpos_sync_test_rest_do_request_response'], $GLOBALS['wcpos_sync_test_rest_do_request_calls'], $GLOBALS['wcpos_sync_test_rest_do_request_queue'], $GLOBALS['wcpos_sync_test_wc_permissions'] );
		wp_set_current_user( 0 );
		add_filter( 'rest_pre_dispatch', array( $this, 'intercept_wc_request' ), 1, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'rest_pre_dispatch', array( $this, 'intercept_wc_request' ), 1 );
		delete_option( 'woocommerce_pos_sync_legacy_revision_grace' );
		unset( $GLOBALS['wcpos_sync_test_rest_do_request_response'], $GLOBALS['wcpos_sync_test_rest_do_request_calls'], $GLOBALS['wcpos_sync_test_rest_do_request_queue'], $GLOBALS['wcpos_sync_test_wc_permissions'] );
		parent::tearDown();
	}

	public function intercept_wc_request( $result, $server, WP_REST_Request $request ) {
		if ( 0 !== strpos( $request->get_route(), '/wc/v3/' ) ) {
			return $result;
		}
		$GLOBALS['wcpos_sync_test_rest_do_request_calls'][] = $request;
		if ( 'GET' !== $request->get_method() ) {
			foreach ( array( 'product', 'product_variation', 'shop_coupon' ) as $post_type ) {
				foreach ( array( 'create', 'edit', 'delete' ) as $context ) {
					$GLOBALS['wcpos_sync_test_wc_permissions'][ $post_type ][ $context ] = apply_filters( 'woocommerce_rest_check_permissions', false, $context, 0, $post_type );
				}
			}
			$GLOBALS['wcpos_sync_test_wc_permissions']['product']['read'] = apply_filters( 'woocommerce_rest_check_permissions', false, 'read', 0, 'product' );
			foreach ( array( 'read', 'create', 'edit', 'delete' ) as $context ) {
				$GLOBALS['wcpos_sync_test_wc_permissions']['shop_order'][ $context ] = apply_filters( 'woocommerce_rest_check_permissions', false, $context, 0, 'shop_order' );
			}
		}
		if ( ! empty( $GLOBALS['wcpos_sync_test_rest_do_request_queue'] ) ) {
			return array_shift( $GLOBALS['wcpos_sync_test_rest_do_request_queue'] );
		}
		if ( isset( $GLOBALS['wcpos_sync_test_rest_do_request_response'] ) ) {
			return $GLOBALS['wcpos_sync_test_rest_do_request_response'];
		}
		return $result;
	}

	private function request( array $params ): WP_REST_Request {
		$r = new WP_REST_Request( 'POST', '' );
		$r->set_header( 'Content-Type', 'application/json' );
		// The controller reads the canonical envelope from the JSON body (get_json_params); a real
		// JSON body is required so an absent key (e.g. a delete's payload) stays absent, exactly as
		// the lab stub's get_json_params returned set_body_params verbatim (tests/bootstrap.php:148).
		$r->set_body( (string) wp_json_encode( $params ) );
		return $r;
	}

	private function envelope( array $over = array() ): array {
		$envelope = array_merge(
			array(
				'collection'   => 'customers',
				'mutationId'   => self::MID,
				'operation'    => 'create',
				'recordId'     => self::REC,
				'baseRevision' => null,
				'payload'      => array(
					'email' => 'a@b.c',
					'meta_data' => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
					),
				),
			),
			$over
		);
		if ( 'delete' === $envelope['operation'] && array_key_exists( 'payload', $over ) && null === $over['payload'] ) {
			unset( $envelope['payload'] );
		}
		return $envelope;
	}

	private function real_order_payload(): array {
		$order = OrderHelper::create_order();
		$order->set_status( 'processing' );
		$order->save();
		$payload = ( new Order_Serializer() )->serialize_order( $order->get_id(), new WP_REST_Request() );
		return array( $order->get_id(), $payload );
	}

	private function setRestResponse( $data, int $status = 200 ): void {
		$GLOBALS['wcpos_sync_test_rest_do_request_response'] = new WP_REST_Response( $data, $status );
	}

	private function push( Fake_Mutation_Store $store, array $envOver = array() ) {
		return ( new Write_Controller( $store ) )->push( $this->request( $this->envelope( $envOver ) ) );
	}

	private function metaUuid( array $data ): ?string {
		foreach ( $data['meta_data'] ?? array() as $e ) {
			if ( Pos_Uuid::META_KEY === $e['key'] ) {
				return $e['value'];
			}
		}
		return null;
	}

	public function test_unknown_collection_is_rejected_400_without_reserving(): void {
		$store = new Fake_Mutation_Store();
		$result = $this->push( $store, array( 'collection' => 'nonsense' ) );
		$this->assertSame( 'woo_rxdb_sync_unknown_collection', $result->get_error_code() );
		$this->assertSame( array(), $store->reserved );
	}

	public function test_validate_rejects_bad_envelope_before_reserving(): void {
		$store = new Fake_Mutation_Store();
		$c     = new Write_Controller( $store );
		$this->assertSame( 'woo_rxdb_sync_bad_mutation_id', $c->push( $this->request( $this->envelope( array( 'mutationId' => 'nope' ) ) ) )->get_error_code() );
		$this->assertSame( 'woo_rxdb_sync_bad_operation', $c->push( $this->request( $this->envelope( array( 'operation' => 'frob' ) ) ) )->get_error_code() );
		$this->assertSame( 'woo_rxdb_sync_bad_record_id', $c->push( $this->request( $this->envelope( array( 'recordId' => 'nope' ) ) ) )->get_error_code() );
		$this->assertSame( 'woo_rxdb_sync_bad_payload', $c->push( $this->request( $this->envelope( array( 'payload' => 'not-an-array' ) ) ) )->get_error_code() );
		$bad = $this->envelope(
			array(
				'payload' => array(
					'meta_data' => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => '00000000-0000-4000-8000-000000000099',
						),
					),
				),
			)
		);
		$this->assertSame( 'woo_rxdb_sync_identity_conflict', $c->push( $this->request( $bad ) )->get_error_code() );
		$this->assertSame( array(), $store->reserved ); // nothing reserved for an invalid envelope
	}

	public function test_create_reserves_forwards_persists_uuid_and_finalizes(): void {
		$store = new Fake_Mutation_Store(); // resolve=0, reserve wins
		$this->setRestResponse(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			201
		); // wc/v3 dropped the protected uuid
		$result = $this->push( $store );
		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 4242, $result->get_data()['document']['id'] );
		$this->assertSame( self::REC, $this->metaUuid( $result->get_data()['document'] ) ); // uuid reflected in the document
		$this->assertStringStartsWith( 'sha256:', $result->get_data()['currentRevision'] ); // revision for the next update's baseRevision
		$this->assertSame( array( self::MID ), $store->reserved );
		$this->assertSame( array( self::MID => 4242 ), $store->finalized );
		$this->assertSame( array(), $store->released );
		$this->assertSame(
			array(
				array(
					'id_type' => 'user',
					'id' => 4242,
					'uuid' => self::REC,
				),
			),
			$store->persisted
		);
		$this->assertCount( 2, $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ); // POST plus coherent GET ack
	}

	/**
	 * Cashier write forwarding relaxes the catalog mutation checks, and re-maps
	 * shop_order contexts through the caps the cashier actually holds (never wider).
	 */
	public function test_cashier_push_scoped_inner_permissions_allow_catalog_mutations(): void {
		$cashier_id = self::factory()->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier_id );
		$this->setRestResponse( array( 'id' => 4242 ), 201 );

		$this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'products',
				'payload'    => array( 'name' => 'Cashier product' ),
			)
		);

		foreach ( array( 'product', 'product_variation', 'shop_coupon' ) as $post_type ) {
			foreach ( array( 'create', 'edit', 'delete' ) as $context ) {
				$this->assertTrue( $GLOBALS['wcpos_sync_test_wc_permissions'][ $post_type ][ $context ], $post_type . ':' . $context );
			}
		}
		$this->assertFalse( $GLOBALS['wcpos_sync_test_wc_permissions']['product']['read'] );
		// Orders re-map through the caps the cashier role actually holds (the HPOS
		// placehold post type breaks WC's own mapping): cashier has publish/edit/
		// read_private but NOT delete_shop_orders, so delete stays denied.
		$this->assertTrue( $GLOBALS['wcpos_sync_test_wc_permissions']['shop_order']['create'] );
		$this->assertFalse( $GLOBALS['wcpos_sync_test_wc_permissions']['shop_order']['edit'] ); // edit requires an object id
		$this->assertTrue( $GLOBALS['wcpos_sync_test_wc_permissions']['shop_order']['read'] );
		$this->assertFalse( $GLOBALS['wcpos_sync_test_wc_permissions']['shop_order']['delete'] );
		$this->assertFalse( apply_filters( 'woocommerce_rest_check_permissions', false, 'create', 0, 'product' ) );
	}

	public function test_order_edit_permission_respects_order_ownership(): void {
		$cashier_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cashier    = get_user_by( 'id', $cashier_id );
		$cashier->add_cap( 'access_woocommerce_pos' );
		$cashier->add_cap( 'edit_shop_orders' );
		$cashier->remove_cap( 'edit_others_shop_orders' );
		$own_order_id = self::factory()->post->create(
			array(
				'post_author' => $cashier_id,
				'post_type'   => 'shop_order_placehold',
			)
		);
		$other_order_id = self::factory()->post->create(
			array(
				'post_author' => $other_id,
				'post_type'   => 'shop_order_placehold',
			)
		);
		wp_set_current_user( $cashier_id );
		$controller = new Write_Controller( new Fake_Mutation_Store() );

		$this->assertTrue( $controller->wcpos_check_permissions( false, 'edit', $own_order_id, 'shop_order' ) );
		$this->assertFalse( $controller->wcpos_check_permissions( false, 'edit', $other_order_id, 'shop_order' ) );
	}

	public function test_order_delete_permission_respects_order_ownership(): void {
		$cashier_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cashier    = get_user_by( 'id', $cashier_id );
		$cashier->add_cap( 'access_woocommerce_pos' );
		$cashier->add_cap( 'delete_shop_orders' );
		$cashier->remove_cap( 'delete_others_shop_orders' );
		$own_order_id = self::factory()->post->create(
			array(
				'post_author' => $cashier_id,
				'post_type'   => 'shop_order_placehold',
			)
		);
		$other_order_id = self::factory()->post->create(
			array(
				'post_author' => $other_id,
				'post_type'   => 'shop_order_placehold',
			)
		);
		wp_set_current_user( $cashier_id );
		$controller = new Write_Controller( new Fake_Mutation_Store() );

		$this->assertTrue( $controller->wcpos_check_permissions( false, 'delete', $own_order_id, 'shop_order' ) );
		$this->assertFalse( $controller->wcpos_check_permissions( false, 'delete', $other_order_id, 'shop_order' ) );
	}

	/**
	 * The shop_order re-map grants nothing beyond the user's own role caps: a user
	 * without any shop_orders capabilities keeps every order context denied.
	 */
	public function test_capless_push_scoped_inner_permissions_deny_order_mutations(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->setRestResponse( array( 'id' => 4242 ), 201 );

		$this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'products',
				'payload'    => array( 'name' => 'Capless product' ),
			)
		);

		foreach ( array( 'read', 'create', 'edit', 'delete' ) as $context ) {
			$this->assertFalse( $GLOBALS['wcpos_sync_test_wc_permissions']['shop_order'][ $context ], 'shop_order:' . $context );
		}
	}

	/**
	 * POS-legit values the stock wc/v3 order schema rejects are dropped from the
	 * forwarded body (v1 relaxed the schema itself — see V1\Orders_Controller
	 * wcpos_get_item_schema): a blank billing.email (walk-in sale, fails the email
	 * format check) and line_items[].parent_name null (WC recomputes it). A raw
	 * forward would 400 the CREATE and strand the record client-side forever.
	 *
	 * @dataProvider wcRejectedCreateBillingEmails
	 */
	public function test_order_forward_drops_wc_rejected_pos_values( $billing_email ): void {
		$store = new Fake_Mutation_Store();
		$store->resolveResults = array( 0, 901 );
		$GLOBALS['wcpos_sync_test_rest_do_request_queue'] = array(
			new WP_REST_Response( array( 'id' => 901 ), 201 ),
			new WP_REST_Response( array( 'id' => 901 ), 200 ),
		);

		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array(
					'billing'    => array(
						'first_name' => 'Walk-in',
						'last_name'  => 'Customer',
						'email'      => $billing_email,
					),
					'line_items' => array(
						array(
							'product_id'  => 55,
							'name'        => 'Simple product',
							'quantity'    => 1,
							'parent_name' => null,
						),
					),
					'meta_data'  => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
					),
				),
			)
		);

		$this->assertSame( 201, $result->get_status() );
		$forwarded = $GLOBALS['wcpos_sync_test_rest_do_request_calls'][0]->get_body_params();
		$this->assertArrayNotHasKey( 'email', $forwarded['billing'] );
		$this->assertSame( 'Walk-in', $forwarded['billing']['first_name'] );
		$this->assertSame( 'Customer', $forwarded['billing']['last_name'] );
		$this->assertArrayNotHasKey( 'parent_name', $forwarded['line_items'][0] );
		$this->assertSame( 55, $forwarded['line_items'][0]['product_id'] ); // rest of the line intact
	}

	public static function wcRejectedCreateBillingEmails(): array {
		return array(
			'empty string' => array( '' ),
			'null'         => array( null ),
		);
	}

	/**
	 * UPDATE keeps the strict-schema workaround while applying an explicit email clear.
	 */
	public function test_order_update_forward_drops_empty_email_but_keeps_real_values(): void {
		list( $order_id, $bare ) = $this->real_order_payload();
		$order = wc_get_order( $order_id );
		$order->set_billing_email( 'stale@example.test' );
		$order->save();
		$bare = ( new Order_Serializer() )->serialize_order( $order_id, new WP_REST_Request() );
		$cleared = $bare;
		$cleared['billing']['email'] = '';
		$store = new Fake_Mutation_Store();
		$store->resolve = $order_id;
		$revision = Order_Serializer::canonical_revision( $bare );
		$GLOBALS['wcpos_sync_test_rest_do_request_queue'] = array(
			new WP_REST_Response( $bare, 200 ),
			new WP_REST_Response( $bare, 200 ),
			new WP_REST_Response( $cleared, 200 ),
		);

		$result = $this->push(
			$store,
			array(
				'operation'    => 'update',
				'collection'   => 'orders',
				'baseRevision' => $revision,
				'payload'      => array(
					'billing'    => array(
						'first_name' => 'Kept',
						'email'      => '',
					),
					'line_items' => array(
						array(
							'product_id'  => 66,
							'name'        => 'Line kept',
							'quantity'    => 2,
							'parent_name' => 'Variable parent', // non-null → preserved
						),
					),
					'meta_data'  => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
					),
				),
			)
		);

		$puts = array_values(
			array_filter(
				$GLOBALS['wcpos_sync_test_rest_do_request_calls'],
				static fn( WP_REST_Request $r ) => 'PUT' === $r->get_method()
			)
		);
		$this->assertNotEmpty( $puts );
		$forwarded = $puts[0]->get_body_params();
		$this->assertArrayNotHasKey( 'email', $forwarded['billing'] );
		$this->assertSame( 'Kept', $forwarded['billing']['first_name'] );
		$this->assertSame( 'Variable parent', $forwarded['line_items'][0]['parent_name'] );
		$this->assertSame( '', wc_get_order( $order_id )->get_billing_email() );
		$this->assertSame( '', $result->get_data()['document']['billing']['email'] );
		$revision_document = $result->get_data()['document'];
		unset( $revision_document['tax_ids'] ); // ack-only decoration; wc/v3 revision source omits it.
		$this->assertSame( Order_Serializer::canonical_revision( $revision_document ), $result->get_data()['currentRevision'] );
	}

	/**
	 * Line-item REMOVAL uses wc/v3's null-as-delete convention: the client marks a
	 * pushed line for deletion by nulling product_id (fees: name, shipping:
	 * method_id, coupons: code — see WC_REST_Orders_V2_Controller::item_is_null).
	 * This must survive the v2 forward's strict-schema validation END TO END, so
	 * this test runs against the REAL wc/v3 dispatch (no stubbed responses) — it
	 * guards the whole create → remove round trip, not just our sanitizer.
	 */
	public function test_order_update_removes_line_item_via_null_product_id_through_real_wc(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$product = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product();
		$store   = new Fake_Mutation_Store();
		$created = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array(
					'status'     => 'processing',
					'line_items' => array(
						array(
							'product_id' => $product->get_id(),
							'quantity'   => 1,
						),
					),
					'meta_data'  => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
					),
				),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$order_id = (int) $created->get_data()['document']['id'];
		$lines    = $created->get_data()['document']['line_items'];
		$this->assertCount( 1, $lines );
		$line_id  = (int) $lines[0]['id'];
		$revision = $created->get_data()['currentRevision'];

		$store->resolve = $order_id;
		$removed = $this->push(
			$store,
			array(
				'collection'   => 'orders',
				'operation'    => 'update',
				'mutationId'   => '00000000-0000-4000-8000-00000000dead',
				'baseRevision' => $revision,
				'payload'      => array(
					'line_items' => array(
						array(
							'id'         => $line_id,
							'product_id' => null,
						),
					),
					'meta_data'  => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
					),
				),
			)
		);

		$this->assertSame( 200, $removed->get_status() );
		$this->assertCount( 0, wc_get_order( $order_id )->get_items() );
	}

	public function test_order_create_preserves_valid_client_date_created_gmt_through_real_wc(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$client_date = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );

		$created = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array( 'date_created_gmt' => $client_date ),
			)
		);

		$this->assertSame( 201, $created->get_status() );
		$order = wc_get_order( (int) $created->get_data()['document']['id'] );
		$this->assertSame( $client_date, gmdate( 'Y-m-d\TH:i:s\Z', $order->get_date_created()->getTimestamp() ) );
	}

	public function test_order_create_rejects_invalid_client_date_created_gmt(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array( 'date_created_gmt' => 'not-a-date' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_rest_invalid_date_created_gmt', $result->get_error_code() );
	}

	public function test_order_create_rejects_client_date_created_gmt_more_than_24_hours_future(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array( 'date_created_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS + HOUR_IN_SECONDS ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_rest_future_date_created_gmt', $result->get_error_code() );
	}

	public function test_order_create_without_date_created_gmt_uses_server_time_through_real_wc(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$created = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array( 'status' => 'processing' ),
			)
		);

		$this->assertSame( 201, $created->get_status() );
		$this->assertNotFalse( wc_get_order( (int) $created->get_data()['document']['id'] ) );
	}

	public function test_order_create_persists_explicit_tax_ids_and_returns_them_in_ack(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tax_ids = array(
			array(
				'type'    => Tax_Id_Types::TYPE_BR_CPF,
				'value'   => '12345678909',
				'country' => 'BR',
			),
		);

		$created = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array(
					'billing' => array( 'country' => 'BR' ),
					'tax_ids' => $tax_ids,
				),
			)
		);

		$this->assertSame( 201, $created->get_status() );
		$order_tax_ids = ( new Tax_Id_Reader() )->read_for_order( wc_get_order( (int) $created->get_data()['document']['id'] ) );
		$this->assertCount( 1, $order_tax_ids );
		$this->assertSame( '12345678909', $order_tax_ids[0]['value'] );
		$this->assertSame( $order_tax_ids, $created->get_data()['document']['tax_ids'] );
	}

	public function test_order_create_snapshots_customer_tax_ids_when_payload_omits_them(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		( new Tax_Id_Writer() )->write_for_user(
			$customer_id,
			array(
				array(
					'type'    => Tax_Id_Types::TYPE_EU_VAT,
					'value'   => 'DE123456789',
					'country' => 'DE',
				),
			)
		);

		$created = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array(
					'customer_id' => $customer_id,
					'billing'     => array( 'country' => 'DE' ),
				),
			)
		);

		$this->assertSame( 201, $created->get_status() );
		$order_tax_ids = ( new Tax_Id_Reader() )->read_for_order( wc_get_order( (int) $created->get_data()['document']['id'] ) );
		$this->assertCount( 1, $order_tax_ids );
		$this->assertSame( 'DE123456789', $order_tax_ids[0]['value'] );
	}

	public function test_order_update_overwrites_explicit_tax_ids_and_omission_does_not_clobber_them(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store = new Fake_Mutation_Store();
		$created = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array(
					'billing' => array( 'country' => 'BR' ),
					'tax_ids' => array(
						array(
							'type'  => Tax_Id_Types::TYPE_BR_CPF,
							'value' => '12345678909',
						),
					),
				),
			)
		);
		$order_id = (int) $created->get_data()['document']['id'];

		$updated = $this->push(
			$store,
			array(
				'collection'   => 'orders',
				'operation'    => 'update',
				'mutationId'   => '00000000-0000-4000-8000-00000000f001',
				'baseRevision' => $created->get_data()['currentRevision'],
				'payload'      => array(
					'tax_ids' => array(
						array(
							'type'  => Tax_Id_Types::TYPE_BR_CNPJ,
							'value' => '12345678000195',
						),
					),
				),
			)
		);
		$updated_tax_ids = ( new Tax_Id_Reader() )->read_for_order( wc_get_order( $order_id ) );
		$this->assertCount( 1, $updated_tax_ids );
		$this->assertSame( '12345678000195', $updated_tax_ids[0]['value'] );

		$without_tax_ids = $this->push(
			$store,
			array(
				'collection'   => 'orders',
				'operation'    => 'update',
				'mutationId'   => '00000000-0000-4000-8000-00000000f002',
				'baseRevision' => $updated->get_data()['currentRevision'],
				'payload'      => array( 'status' => 'completed' ),
			)
		);

		$this->assertSame( 200, $without_tax_ids->get_status() );
		$preserved_tax_ids = ( new Tax_Id_Reader() )->read_for_order( wc_get_order( $order_id ) );
		$this->assertCount( 1, $preserved_tax_ids );
		$this->assertSame( '12345678000195', $preserved_tax_ids[0]['value'] );
	}

	public function test_order_create_rejects_malformed_tax_ids_before_forwarding(): void {
		// An unsupported type would be silently remapped to `other` by Tax_Id_Writer once tax_ids is
		// stripped from the wc/v3 forward; the v1 schema enum rejects it with a 400 instead. Reject
		// BEFORE forwarding so no order is created for a mutation whose ack would drop the client's IDs.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array(
					'billing' => array( 'country' => 'BR' ),
					'tax_ids' => array(
						array(
							'type'  => 'not_a_supported_type',
							'value' => '12345678909',
						),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_rest_invalid_tax_ids', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	/**
	 * @dataProvider provide_incomplete_tax_id_entries
	 */
	public function test_order_create_rejects_tax_id_entry_missing_required_field_before_forwarding( array $entry ): void {
		// value/type are required by the schema: without them Tax_Id_Writer would drop the entry or
		// remap it to `other`, so the ack would silently differ from what the client submitted. Reject
		// with a 400 BEFORE forwarding so no order is created for a mutation whose ack would mutate it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array(
					'billing' => array( 'country' => 'BR' ),
					'tax_ids' => array( $entry ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_rest_invalid_tax_ids', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	public function provide_incomplete_tax_id_entries(): array {
		return array(
			'missing value' => array( array( 'type' => 'eu_vat' ) ),
			'missing type'  => array( array( 'value' => '12345678909' ) ),
			'empty object'  => array( array() ),
		);
	}

	public function test_order_create_rejects_non_object_tax_id_entry_before_forwarding(): void {
		// Each entry must be an object per the v1 schema — a bare scalar would be dropped by the
		// writer's is_array() guard; validate here so the client learns their submission was rejected.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $this->push(
			new Fake_Mutation_Store(),
			array(
				'collection' => 'orders',
				'payload'    => array(
					'billing' => array( 'country' => 'BR' ),
					'tax_ids' => array( 'not-an-object' ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_rest_invalid_tax_ids', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	public function test_order_update_rejects_malformed_tax_ids_before_forwarding(): void {
		// Updates strip tax_ids from the wc/v3 forward too, so the same v1 schema check applies.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store   = new Fake_Mutation_Store();
		$created = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array( 'billing' => array( 'country' => 'BR' ) ),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$forwards_after_create = \count( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );

		$result = $this->push(
			$store,
			array(
				'collection'   => 'orders',
				'operation'    => 'update',
				'mutationId'   => '00000000-0000-4000-8000-00000000f003',
				'baseRevision' => $created->get_data()['currentRevision'],
				'payload'      => array(
					'tax_ids' => array(
						array(
							'type'  => 'not_a_supported_type',
							'value' => '12345678909',
						),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_pos_rest_invalid_tax_ids', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		// The malformed update must be rejected before the PUT forward (no new wc/v3 write).
		$this->assertSame( $forwards_after_create, \count( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() ) );
	}

	public function test_order_poison_recovery_replays_tax_ids_without_reforwarding(): void {
		// A create can die after mark_poison() but before the separate tax-ID save. Prove the poison
		// recovery path replays the create-time tax_ids persistence: reach poison via a failed uuid
		// stamp (which DID persist tax IDs on the first attempt), strip them to model the crash, then
		// retry and assert recovery restores them — without a second wc/v3 forward.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store = new Fake_Mutation_Store();
		$store->persistUuidOk = false;
		$env = array(
			'collection' => 'orders',
			'payload'    => array(
				'billing' => array( 'country' => 'BR' ),
				'tax_ids' => array(
					array(
						'type'    => Tax_Id_Types::TYPE_BR_CPF,
						'value'   => '12345678909',
						'country' => 'BR',
					),
				),
			),
		);

		$first = $this->push( $store, $env );
		$this->assertSame( 'woo_rxdb_sync_identity_persistence_failed', $first->get_error_code() );
		$order_id = $store->poisoned[ self::MID ];
		$this->assertGreaterThan( 0, $order_id );

		// Model the crash-before-tax-save: wipe the tax IDs the first attempt happened to write.
		( new Tax_Id_Writer() )->write_for_order( wc_get_order( $order_id ), array() );
		$this->assertCount( 0, ( new Tax_Id_Reader() )->read_for_order( wc_get_order( $order_id ) ) );
		$posts_before_retry = $this->count_forward_posts();

		$store->persistUuidOk = true;
		$retry = $this->push( $store, $env );

		$this->assertSame( 201, $retry->get_status() );
		// Recovery re-reads via a wc/v3 GET but must NOT re-create the order.
		$this->assertSame( $posts_before_retry, $this->count_forward_posts() );
		$recovered = ( new Tax_Id_Reader() )->read_for_order( wc_get_order( $order_id ) );
		$this->assertCount( 1, $recovered );
		$this->assertSame( '12345678909', $recovered[0]['value'] );
		$this->assertSame( $recovered, $retry->get_data()['document']['tax_ids'] );
	}

	private function count_forward_posts(): int {
		return \count(
			array_filter(
				$GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array(),
				static function ( WP_REST_Request $r ) {
					return 'POST' === $r->get_method();
				}
			)
		);
	}

	public function test_create_order_persists_server_authoritative_pos_audit_meta_directly(): void {
		// gap §3.3: the audit meta is persisted DIRECTLY after create (server-authoritative). Pro
		// analytics joins on created_via/_pos_user (a client can't forge channel/cashier); the
		// till-sourced _pos_store + cash meta are preserved from the client payload.
		$store = new Fake_Mutation_Store();
		$store->resolveResults = array( 0, 900 ); // create resolve (born-twice check) miss, then post-create reload
		$cashier_id = self::factory()->user->create();
		wp_set_current_user( $cashier_id );
		// POST create → 201; GET re-read → 200 (distinct, so the status-preservation is actually tested).
		$GLOBALS['wcpos_sync_test_rest_do_request_queue'] = array(
			new WP_REST_Response( array( 'id' => 900 ), 201 ),
			new WP_REST_Response( array( 'id' => 900 ), 200 ),
		);

		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array(
					'total'     => '9.99',
					'meta_data' => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
						array(
							'key' => '_pos_user',
							'value' => '999',
						),                   // client-forged → ignored
						array(
							'key' => '_woocommerce_pos_version',
							'value' => '0.0.0',
						),                   // client-forged → replaced by the accepting server version
						array(
							'key' => '_pos_store',
							'value' => '3',
						),                    // till → preserved
						array(
							'key' => '_pos_cash_amount_tendered',
							'value' => '20.00',
						), // till → preserved
					),
				),
			)
		);

		$this->assertCount( 1, $store->auditMeta );
		$call = $store->auditMeta[0];
		$this->assertSame( 900, $call['id'] );
		$this->assertSame( 'woocommerce-pos', $call['created_via'] );          // channel enforced
		$this->assertSame( (string) $cashier_id, $call['meta']['_pos_user'] );                 // server-derived cashier, not client's 999
		$this->assertSame( VERSION, $call['meta']['_woocommerce_pos_version'] ); // accepting server version
		$this->assertSame( '3', $call['meta']['_pos_store'] );                 // till store preserved
		$this->assertSame( '20.00', $call['meta']['_pos_cash_amount_tendered'] ); // cash meta preserved

		// The forwarded create carries created_via (WC honors it) but has the server-managed _pos_*
		// audit keys STRIPPED — so a client-forged copy can't land at create and defeat the write-once
		// server stamp. The uuid (a different protected key) is untouched.
		$body     = $GLOBALS['wcpos_sync_test_rest_do_request_calls'][0]->get_body_params();
		$fwdKeys  = array_map( static fn ( $e ) => $e['key'], $body['meta_data'] );
		$this->assertSame( 'woocommerce-pos', $body['created_via'] );
		$this->assertNotContains( '_pos_user', $fwdKeys );
		$this->assertNotContains( '_woocommerce_pos_version', $fwdKeys );
		$this->assertNotContains( '_pos_store', $fwdKeys );
		$this->assertNotContains( '_pos_cash_amount_tendered', $fwdKeys );
		$this->assertContains( Pos_Uuid::META_KEY, $fwdKeys ); // uuid not stripped

		// A fresh create reports the POST's 201, not the GET re-read's 200.
		$this->assertSame( 201, $result->get_status() );
	}

	public function test_non_order_create_persists_no_audit_meta(): void {
		$store = new Fake_Mutation_Store();
		$cashier_id = self::factory()->user->create();
		wp_set_current_user( $cashier_id );
		$this->setRestResponse( array( 'id' => 4242 ), 201 );
		$this->push( $store ); // default collection = customers (id_type 'user')

		$this->assertSame( array(), $store->auditMeta ); // not an order → no audit-meta write
	}

	public function test_order_audit_validates_till_values_cash_numeric_store_is_an_identifier(): void {
		// Till meta bypasses wc/v3 validation. Cash AMOUNTS must be numeric (a malformed one is
		// dropped). _pos_store is an IDENTIFIER — the store-scope model allows a uuid/slug — so a
		// non-numeric store id is preserved.
		$store = new Fake_Mutation_Store();
		$store->resolveResults = array( 0, 900 );
		$cashier_id = self::factory()->user->create();
		wp_set_current_user( $cashier_id );
		$GLOBALS['wcpos_sync_test_rest_do_request_queue'] = array(
			new WP_REST_Response( array( 'id' => 900 ), 201 ),
			new WP_REST_Response( array( 'id' => 900 ), 200 ),
		);

		$this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array(
					'meta_data' => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
						array(
							'key' => '_pos_store',
							'value' => 'store-uuid-abc',
						),         // identifier → kept
						array(
							'key' => '_pos_cash_amount_tendered',
							'value' => 'not-a-num',
						), // bad amount → dropped
						array(
							'key' => '_pos_cash_change',
							'value' => '5.00',
						),             // valid amount → kept
					),
				),
			)
		);

		$meta = $store->auditMeta[0]['meta'];
		$this->assertSame( 'store-uuid-abc', $meta['_pos_store'] );          // string store id preserved
		$this->assertArrayNotHasKey( '_pos_cash_amount_tendered', $meta );   // non-numeric amount dropped
		$this->assertSame( '5.00', $meta['_pos_cash_change'] );              // numeric amount kept
		$this->assertSame( (string) $cashier_id, $meta['_pos_user'] );
	}

	public function test_order_update_strips_audit_meta_from_the_forwarded_body(): void {
		list($order_id, $bare) = $this->real_order_payload();
		$store = new Fake_Mutation_Store();
		$store->resolve = $order_id;
		$revision = Order_Serializer::canonical_revision( $bare );
		$this->setRestResponse( $bare, 200 );

		$this->push(
			$store,
			array(
				'operation' => 'update',
				'collection' => 'orders',
				'baseRevision' => $revision,
				'payload' => array(
					'status' => 'completed',
					'created_via' => 'online',
					'meta_data' => array(
						array(
							'key' => '_pos_user',
							'value' => '999',
						),
						array(
							'key' => '_pos_store',
							'value' => '5',
						),
						array(
							'key' => 'custom',
							'value' => 'keep',
						),
					),
				),
			)
		);

		$put = null;
		foreach ( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] as $call ) {
			if ( 'PUT' === $call->get_method() ) {
				$put = $call;
				break; }
		}
		$this->assertNotNull( $put );
		$body = $put->get_body_params();
		$keys = array_map( static fn( $entry ) => $entry['key'], $body['meta_data'] );
		$this->assertNotContains( '_pos_user', $keys );
		$this->assertNotContains( '_pos_store', $keys );
		$this->assertContains( 'custom', $keys );
		$this->assertArrayNotHasKey( 'created_via', $body );
	}


	public function test_malformed_order_meta_data_passes_through_to_woo_validation(): void {
		// A non-array meta_data must NOT be silently replaced with [] — it passes through so wc/v3's
		// schema validation rejects it, rather than finalizing a write with metadata discarded.
		$store = new Fake_Mutation_Store();
		$store->resolveResults = array( 0, 900 );
		$this->setRestResponse( array( 'id' => 900 ), 201 );

		$this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array( 'meta_data' => 'not-an-array' ),
			)
		);

		$body = $GLOBALS['wcpos_sync_test_rest_do_request_calls'][0]->get_body_params();
		$this->assertSame( 'not-an-array', $body['meta_data'] ); // untouched → wc/v3 validates + rejects
	}

	public function test_order_audit_tolerates_a_malformed_meta_key_without_fataling(): void {
		// A meta_data entry whose `key` is an array/object must not be used as an array offset
		// (PHP fatal) — it's skipped, and well-formed entries still process.
		$store = new Fake_Mutation_Store();
		$store->resolveResults = array( 0, 900 );
		$cashier_id = self::factory()->user->create();
		wp_set_current_user( $cashier_id );
		$GLOBALS['wcpos_sync_test_rest_do_request_queue'] = array(
			new WP_REST_Response( array( 'id' => 900 ), 201 ),
			new WP_REST_Response( array( 'id' => 900 ), 200 ),
		);

		$this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array(
					'meta_data' => array(
						array(
							'key' => array( 'nested' ),
							'value' => 'x',
						), // malformed key → skipped, no fatal
						array(
							'key' => '_pos_store',
							'value' => '3',
						),
					),
				),
			)
		);

		$this->assertSame( '3', $store->auditMeta[0]['meta']['_pos_store'] ); // well-formed entry still processed
	}

	public function test_born_twice_order_create_restamps_audit_meta(): void {
		// A retry after a crash that persisted the order but not (all of) its audit meta hits the
		// born-twice guard; it must re-stamp so the recovered order still carries the audit trail.
		$store = new Fake_Mutation_Store();
		$store->resolve = 900; // order already exists → born-twice path (no POST)
		$cashier_id = self::factory()->user->create();
		wp_set_current_user( $cashier_id );
		$this->setRestResponse( array( 'id' => 900 ), 200 );

		$this->push(
			$store,
			array(
				'collection' => 'orders',
				'payload'    => array(
					'meta_data' => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => self::REC,
						),
						array(
							'key' => '_pos_store',
							'value' => '3',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $store->auditMeta );
		$this->assertSame( 900, $store->auditMeta[0]['id'] );
		$this->assertSame( 'woocommerce-pos', $store->auditMeta[0]['created_via'] );
		$this->assertSame( (string) $cashier_id, $store->auditMeta[0]['meta']['_pos_user'] );
		$this->assertArrayNotHasKey( '_woocommerce_pos_version', $store->auditMeta[0]['meta'] );
		$this->assertSame( '3', $store->auditMeta[0]['meta']['_pos_store'] );
	}

	public function test_apply_runs_inside_the_per_record_lock(): void {
		// F1: the read-current → compare → forward critical section must be serialised per
		// record, so the resolve (the first thing apply does) falls between acquire and release.
		$store = new Fake_Mutation_Store();
		$store->resolve = 10;
		$this->setRestResponse(
			array(
				'id' => 10,
				'email' => 'a@b.c',
			),
			200
		);
		$this->push( $store, array( 'operation' => 'update' ) ); // null baseRevision → blind update proceeds
		$this->assertSame( array( 'acquire', 'resolve', 'release' ), $store->lockTrace );
	}

	public function test_record_lock_unavailable_returns_409_without_applying(): void {
		// Another writer holds the record past the lock timeout → don't apply (which could
		// lose an update); release the reservation so the client retries.
		$store = new Fake_Mutation_Store();
		$store->recordLockOk = false;
		$store->resolve = 10;
		$result = $this->push( $store, array( 'operation' => 'update' ) );
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $result->get_data()['code'] );
		$this->assertSame( array( self::MID ), $store->released ); // reservation released for a retry
		$this->assertSame( array( 'acquire' ), $store->lockTrace ); // apply never ran, lock never (needed) releasing
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	public function test_orders_are_accepted_on_the_generic_surface_and_keyed_as_an_order(): void {
		$store = new Fake_Mutation_Store(); // resolve=0 ⇒ a fresh create
		$store->resolveResults = array( 0, 7001 ); // no existing order, then uuid resolves after persist_uuid()
		$this->setRestResponse(
			array(
				'id' => 7001,
				'status' => 'processing',
			),
			201
		);
		$result = $this->push( $store, array( 'collection' => 'orders' ) );
		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 7001, $result->get_data()['document']['id'] );
		$this->assertSame( self::REC, $this->metaUuid( $result->get_data()['document'] ) ); // uuid reflected on the order doc
		$this->assertSame( 'order', $store->persisted[0]['id_type'] ); // dispatched as an HPOS order, NOT a post
		$this->assertSame( array( self::MID => 7001 ), $store->finalized );
	}

	public function test_order_create_fails_closed_when_uuid_stamp_cannot_be_verified(): void {
		$store = new Fake_Mutation_Store();
		$store->resolveResults = array( 0, 0 ); // no existing order, and still not resolvable after persist_uuid()
		$this->setRestResponse(
			array(
				'id' => 7001,
				'status' => 'processing',
			),
			201
		);

		$result = $this->push( $store, array( 'collection' => 'orders' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_identity_persistence_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame(
			array(
				array(
					'id_type' => 'order',
					'id' => 7001,
					'uuid' => self::REC,
				),
			),
			$store->persisted
		);
		$this->assertSame( array(), $store->finalized );
		$this->assertSame( array( self::MID => 7001 ), $store->poisoned );
		$this->assertSame( array(), $store->released );
	}

	public function test_orders_update_with_a_stale_revision_conflicts(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 7001; // the uuid resolves to an existing order
		$this->setRestResponse(
			array(
				'id' => 7001,
				'status' => 'processing',
			),
			200
		); // the current-state GET
		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'operation' => 'update',
				'baseRevision' => 'sha256:STALE',
			)
		);
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_conflict', $result->get_data()['code'] );
	}

	public function test_order_ack_revision_is_computed_from_the_returned_document_single_read(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 7001;
		$document = array(
			'id' => 7001,
			'status' => 'processing',
			'total' => '10.00',
		);
		$GLOBALS['wcpos_sync_test_rest_do_request_response'] = new WP_REST_Response( $document, 200 );
		$store->lookups[ self::MID ] = array(
			'remote_id' => 7001,
			'operation' => 'create',
			'record_uuid' => self::REC,
			'status' => 'done',
			'fingerprint' => self::ORDER_FINGERPRINT,
		);

		$result = $this->push( $store, array( 'collection' => 'orders' ) );

		$this->assertSame( $document['status'], $result->get_data()['document']['status'] );
		$this->assertSame( Order_Serializer::canonical_revision( $document ), $result->get_data()['currentRevision'] );
		$this->assertCount( 1, $GLOBALS['wcpos_sync_test_rest_do_request_calls'] );
	}


	public function test_term_id_grace_applies_to_update_but_not_delete(): void {
		$current = array(
			'id' => 10,
			'name' => 'Current',
		);

		$updateStore = new Fake_Mutation_Store();
		$updateStore->resolve = 10;
		$GLOBALS['wcpos_sync_test_rest_do_request_queue'] = array(
			new WP_REST_Response( $current, 200 ),
			new WP_REST_Response(
				array(
					'id' => 10,
					'name' => 'Updated',
				),
				200
			),
			new WP_REST_Response(
				array(
					'id' => 10,
					'name' => 'Updated',
				),
				200
			),
		);
		$updated = $this->push(
			$updateStore,
			array(
				'collection' => 'categories',
				'operation' => 'update',
				'baseRevision' => '10',
				'payload' => array( 'name' => 'Updated' ),
			)
		);
		$this->assertSame( 200, $updated->get_status() );

		unset( $GLOBALS['wcpos_sync_test_rest_do_request_queue'] );
		$deleteStore = new Fake_Mutation_Store();
		$deleteStore->resolve = 10;
		$this->setRestResponse( $current, 200 );
		$deleted = $this->push(
			$deleteStore,
			array(
				'collection' => 'categories',
				'operation' => 'delete',
				'payload' => null,
				'baseRevision' => '10',
			)
		);
		$this->assertSame( 409, $deleted->get_status() );
		$this->assertSame( 'woo_rxdb_sync_conflict', $deleted->get_data()['code'] );
	}

	public function test_orders_update_accepts_LEGACY_revision_over_unchanged_content_under_grace(): void {
		list($order_id, $payload) = $this->real_order_payload();
		$store = new Fake_Mutation_Store();
		$store->resolve = $order_id;
		$legacy = Order_Serializer::legacy_revision( $payload );
		$canonical = Order_Serializer::canonical_revision( $payload );
		$this->assertNotSame( $legacy, $canonical );
		$this->setRestResponse( $payload, 200 );

		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'operation' => 'update',
				'baseRevision' => $legacy,
				'payload' => array( 'status' => 'completed' ),
			)
		);

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( $canonical, $result->get_data()['currentRevision'] );
	}


	public function test_grace_rejects_a_genuinely_stale_legacy_revision(): void {
		list($order_id, $payload) = $this->real_order_payload();
		$store = new Fake_Mutation_Store();
		$store->resolve = $order_id;
		$this->setRestResponse( $payload, 200 );
		$stale_legacy = Order_Serializer::legacy_revision( array( 'status' => 'somewhere-else' ) );

		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'operation' => 'update',
				'baseRevision' => $stale_legacy,
			)
		);

		$this->assertSame( 409, $result->get_status() );
	}


	public function test_grace_accepts_a_pre_1b_date_string_precondition_matching_the_current_document(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 7001;
		$payload = array(
			'id' => 7001,
			'status' => 'processing',
			'date_modified_gmt' => '2026-07-01T10:00:00',
		);
		$this->setRestResponse( $payload, 200 );

		$ok = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'operation' => 'update',
				'baseRevision' => '2026-07-01T10:00:00',
				'payload' => array( 'status' => 'completed' ),
			)
		);
		$this->assertSame( 200, $ok->get_status() );

		$stale_store = new Fake_Mutation_Store();
		$stale_store->resolve = 7001;
		$stale = $this->push(
			$stale_store,
			array(
				'collection' => 'orders',
				'operation' => 'update',
				'baseRevision' => '2020-01-01T00:00:00',
			)
		);
		$this->assertSame( 409, $stale->get_status() );
	}


	public function test_grace_off_rejects_every_legacy_form(): void {
		update_option( 'woocommerce_pos_sync_legacy_revision_grace', 'no' );
		list($order_id, $payload) = $this->real_order_payload();
		$store = new Fake_Mutation_Store();
		$store->resolve = $order_id;
		$this->setRestResponse( $payload, 200 );
		$legacy = Order_Serializer::legacy_revision( $payload );

		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'operation' => 'update',
				'baseRevision' => $legacy,
			)
		);

		$this->assertSame( 409, $result->get_status() );
	}


	public function test_orders_update_accepts_pull_revision_that_ignores_identity_meta(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 7001;
		$payload = array(
			'id' => 7001,
			'status' => 'processing',
			'total' => '10.00',
		);
		$revision = Order_Serializer::canonical_revision( $payload );
		$this->setRestResponse( $payload, 200 );

		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'operation' => 'update',
				'baseRevision' => $revision,
				'payload' => array( 'status' => 'completed' ),
			)
		);

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( $revision, $result->get_data()['currentRevision'] );
		$this->assertSame( array( self::MID => 7001 ), $store->finalized );
		$this->assertSame( 'order', $store->persisted[0]['id_type'] );
		$this->assertSame( array( 'GET', 'PUT', 'GET' ), array_map( static fn( $request ) => $request->get_method(), $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ) );
	}


	public function test_create_born_twice_reuses_existing_and_finalizes_without_forwarding(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 77;
		$this->setRestResponse(
			array(
				'id' => 77,
				'meta_data' => array(
					array(
						'key' => Pos_Uuid::META_KEY,
						'value' => self::REC,
					),
				),
			),
			200
		);
		$result = $this->push( $store );
		$this->assertSame( 77, $result->get_data()['document']['id'] );
		$this->assertSame( array( self::MID => 77 ), $store->finalized );
		$this->assertCount( 1, $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ); // only the GET document_for
		$this->assertSame( 'GET', $GLOBALS['wcpos_sync_test_rest_do_request_calls'][0]->get_method() );
	}

	public function test_create_fails_closed_and_releases_when_wc_v3_returns_no_id(): void {
		$store = new Fake_Mutation_Store();
		$this->setRestResponse( array( 'email' => 'a@b.c' ), 201 ); // 2xx but no usable id
		$result = $this->push( $store );
		$this->assertSame( 'woo_rxdb_sync_create_no_id', $result->get_error_code() );
		$this->assertSame( array(), $store->finalized ); // not finalized
		$this->assertSame( 'blocked', $store->lookups[ self::MID ]['status'] );
		$this->assertSame( array(), $store->released );
	}

	public function test_create_no_id_retains_blocked_marker_and_existing_error(): void {
		$store = new Fake_Mutation_Store();
		$this->setRestResponse( array( 'email' => 'a@b.c' ), 201 );

		$result = $this->push( $store );

		$this->assertSame( 'woo_rxdb_sync_create_no_id', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertSame( 'blocked', $store->lookups[ self::MID ]['status'] );
		$this->assertSame( array(), $store->released );
	}

	public function test_create_no_id_stays_non_reclaimable_when_blocked_transition_fails(): void {
		$store = new Fake_Mutation_Store();
		$store->indeterminateOk = false;
		$this->setRestResponse( array( 'email' => 'a@b.c' ), 202 );

		$result = $this->push( $store );

		$this->assertSame( 'woo_rxdb_sync_create_no_id', $result->get_error_code() );
		$this->assertSame( 'pending', $store->lookups[ self::MID ]['status'] );
		$this->assertSame( array(), $store->released );
		$retry = $this->push( $store );
		$this->assertSame( 'woo_rxdb_sync_in_progress', $retry->get_data()['code'] );
		$this->assertCount( 1, $GLOBALS['wcpos_sync_test_rest_do_request_calls'] );
	}

	public function test_create_checkpoint_failure_stamps_known_identity_before_returning(): void {
		$store = new Fake_Mutation_Store();
		$store->poisonOk = false;
		$this->setRestResponse(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			201
		);

		$result = $this->push( $store );

		$this->assertSame( 'woo_rxdb_sync_finalize_failed', $result->get_error_code() );
		$this->assertSame( 4242, $store->resolve );
		$this->assertSame( 4242, $store->persisted[0]['id'] );
		$this->assertSame( 'blocked', $store->lookups[ self::MID ]['status'] );
		$this->assertSame( array(), $store->released );
	}

	public function test_create_identity_persistence_failure_poison_retries_stamp_original_without_reforwarding(): void {
		$store = new Fake_Mutation_Store();
		$store->persistUuidOk = false;
		$this->setRestResponse(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			201
		);
		$result = $this->push( $store );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( 'woo_rxdb_sync_identity_persistence_failed', $result->get_error_code() );
		$this->assertSame( array(), $store->finalized );
		$this->assertSame( array( self::MID => 4242 ), $store->poisoned );
		$this->assertSame( array(), $store->released );

		$store->persistUuidOk = true;
		$GLOBALS['wcpos_sync_test_rest_do_request_response'] = new WP_REST_Response(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			200
		);
		$retry = $this->push( $store );
		$this->assertSame( 201, $retry->get_status() );
		$this->assertCount( 2, $GLOBALS['wcpos_sync_test_rest_do_request_calls'] );
		$this->assertSame( array( 'POST', 'GET' ), array_map( static fn( $r ) => $r->get_method(), $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ) );
	}

	public function test_order_poison_retry_does_not_guess_accepting_version(): void {
		$store = new Fake_Mutation_Store();
		$store->persistUuidOk = false;
		$this->setRestResponse( array( 'id' => 4242 ), 201 );

		$result = $this->push( $store, array( 'collection' => 'orders' ) );
		$this->assertSame( 'woo_rxdb_sync_identity_persistence_failed', $result->get_error_code() );

		$store->persistUuidOk = true;
		$this->setRestResponse( array( 'id' => 4242 ), 200 );
		$retry = $this->push( $store, array( 'collection' => 'orders' ) );

		$this->assertSame( 201, $retry->get_status() );
		$this->assertCount( 1, $store->auditMeta );
		$this->assertArrayNotHasKey( '_woocommerce_pos_version', $store->auditMeta[0]['meta'] );
	}

	public function test_poison_with_uuid_resolving_to_different_remote_id_errors_without_forwarding(): void {
		$store = new Fake_Mutation_Store();
		$store->lookups[ self::MID ] = array(
			'remote_id' => 4242,
			'operation' => 'create',
			'record_uuid' => self::REC,
			'status' => 'poison',
			'fingerprint' => self::DEFAULT_FINGERPRINT,
		);
		$store->resolve = 9999;
		$result = $this->push( $store );
		$this->assertSame( 'woo_rxdb_sync_identity_persistence_failed', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	public function test_poison_retry_rejects_a_different_record_id_without_stamping_the_original(): void {
		$different = '6c9f2b4d-3a5e-4b7c-8d9f-2e3a4b5c6d7e';
		$store = new Fake_Mutation_Store();
		$store->lookups[ self::MID ] = array(
			'remote_id' => 4242,
			'operation' => 'create',
			'record_uuid' => self::REC,
			'status' => 'poison',
			'fingerprint' => self::DEFAULT_FINGERPRINT,
		);

		$result = $this->push(
			$store,
			array(
				'recordId' => $different,
				'payload'  => array(
					'email' => 'a@b.c',
					'meta_data' => array(
						array(
							'key' => Pos_Uuid::META_KEY,
							'value' => $different,
						),
					),
				),
			)
		);

		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( 'woo_rxdb_sync_bad_mutation_id', $result->get_error_code() );
		$this->assertSame( array(), $store->persisted );
		$this->assertSame( 'poison', $store->lookups[ self::MID ]['status'] );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	public function test_delete_forbids_payload_and_create_rejects_json_array_payload(): void {
		$store = new Fake_Mutation_Store();
		$this->assertSame(
			'woo_rxdb_sync_bad_payload',
			$this->push(
				$store,
				array(
					'operation' => 'delete',
					'payload' => array(),
				)
			)->get_error_code()
		);
		$this->assertSame( 'woo_rxdb_sync_bad_payload', $this->push( $store, array( 'payload' => array( 'not-an-object' ) ) )->get_error_code() );
	}

	public function test_finalize_failure_returns_500_keeps_reservation_and_replay_finalizes_without_reapplying(): void {
		$store = new Fake_Mutation_Store();
		$store->finalizeOk = false;
		$this->setRestResponse(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			202
		);
		$first = $this->push( $store );
		$this->assertSame( 500, $first->get_error_data()['status'] );
		$this->assertSame( 'woo_rxdb_sync_finalize_failed', $first->get_error_code() );
		$this->assertSame( array(), $store->released );
		$this->assertCount( 1, $GLOBALS['wcpos_sync_test_rest_do_request_calls'] );

		$store->finalizeOk = true;
		$store->resolve = 4242;
		$GLOBALS['wcpos_sync_test_rest_do_request_response'] = new WP_REST_Response(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			200
		);
		$second = $this->push( $store );
		$this->assertSame( 202, $second->get_status() );
		$this->assertSame( array( self::MID => 4242 ), $store->finalized );
		$this->assertCount( 2, $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ); // replay GET only; no second POST
		$this->assertSame( 'GET', $GLOBALS['wcpos_sync_test_rest_do_request_calls'][1]->get_method() );
	}

	public function test_update_without_base_revision_is_rejected_428_before_forwarding(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 10;
		$result = $this->push( $store, array( 'operation' => 'update' ) );
		$this->assertSame( 428, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_revision_required', $result->get_data()['code'] );
		$this->assertSame( array( self::MID ), $store->released );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	public function test_update_not_found_404_and_releases(): void {
		$store = new Fake_Mutation_Store(); // resolve=0
		$result = $this->push( $store, array( 'operation' => 'update' ) );
		$this->assertSame( 'woo_rxdb_sync_record_not_found', $result->get_error_code() );
		$this->assertSame( array( self::MID ), $store->released );
	}

	public function test_update_stale_base_revision_409_and_releases(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 10;
		$current = array(
			'id' => 10,
			'email' => 'server@b.c',
			'meta_data' => array(
				array(
					'key' => Pos_Uuid::META_KEY,
					'value' => self::REC,
				),
			),
		);
		$this->setRestResponse( $current, 200 );
		$result = $this->push(
			$store,
			array(
				'operation' => 'update',
				'baseRevision' => 'sha256:stale',
			)
		);
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_conflict', $result->get_data()['code'] );
		$this->assertSame( $current, $result->get_data()['current'] );
		$this->assertSame( array(), $store->finalized );
		$this->assertSame( array( self::MID ), $store->released ); // conflict ⇒ reservation released
	}

	public function test_update_propagates_a_failed_current_read_instead_of_a_false_conflict(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 10; // the uuid resolves to an id…
		$this->setRestResponse(
			array(
				'code' => 'woocommerce_rest_product_invalid_id',
				'message' => 'Invalid ID.',
			),
			404
		); // …but the GET fails
		$result = $this->push(
			$store,
			array(
				'operation' => 'update',
				'baseRevision' => 'sha256:whatever',
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 404, $result->get_status() ); // the real error is propagated…
		$this->assertNotSame( 'woo_rxdb_sync_conflict', $result->get_data()['code'] ?? null ); // …NOT a false stale-revision conflict
		$this->assertSame( array(), $store->finalized );
		$this->assertSame( array( self::MID ), $store->released ); // reservation released (failure)
	}

	public function test_delete_already_gone_is_idempotent_success(): void {
		$store = new Fake_Mutation_Store(); // resolve=0 ⇒ already gone
		$result = $this->push(
			$store,
			array(
				'operation' => 'delete',
				'payload' => null,
			)
		);
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( array( self::MID => 0 ), $store->finalized );
		$this->assertSame( array(), $store->released );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	/**
	 * F4a: a uuid that resolves to MORE THAN ONE record (importer/clone/staging copy) is
	 * ambiguous — every mutation kind must FAIL CLOSED (409 identity_ambiguous, reservation
	 * released, nothing forwarded) rather than write/delete/create against an arbitrary match.
	 */
	private function ambiguousResolveStore(): Fake_Mutation_Store {
		$store = new Fake_Mutation_Store();
		$store->resolve = new WP_Error( 'woo_rxdb_sync_identity_ambiguous', 'uuid on >1 record', array( 'status' => 409 ) );
		return $store;
	}

	public function test_update_with_ambiguous_identity_aborts_409_and_releases(): void {
		$store  = $this->ambiguousResolveStore();
		$result = $this->push( $store, array( 'operation' => 'update' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_identity_ambiguous', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array( self::MID ), $store->released );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() ); // never forwarded
	}

	public function test_delete_with_ambiguous_identity_aborts_409_and_releases(): void {
		$store  = $this->ambiguousResolveStore();
		$result = $this->push(
			$store,
			array(
				'operation' => 'delete',
				'payload' => null,
				'baseRevision' => 'sha256:r',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_identity_ambiguous', $result->get_error_code() );
		$this->assertSame( array( self::MID ), $store->released );
		$this->assertSame( array(), $store->finalized );           // nothing deleted
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	public function test_create_with_ambiguous_identity_aborts_without_inserting_a_third(): void {
		// The born-twice guard resolves the uuid before forwarding; an ambiguous result must
		// abort, NOT POST a third record carrying the duplicated uuid.
		$store  = $this->ambiguousResolveStore();
		$result = $this->push( $store );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_identity_ambiguous', $result->get_error_code() );
		$this->assertSame( array( self::MID ), $store->released );
		$this->assertSame( array(), $store->finalized );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() ); // no POST
	}

	public function test_delete_with_stale_base_revision_409_and_does_not_delete(): void {
		// A stale offline delete must NOT destroy a record another client just updated.
		$store = new Fake_Mutation_Store();
		$store->resolve = 10;
		$current = array(
			'id' => 10,
			'email' => 'server@b.c',
			'meta_data' => array(
				array(
					'key' => Pos_Uuid::META_KEY,
					'value' => self::REC,
				),
			),
		);
		$this->setRestResponse( $current, 200 ); // the current-state GET
		$result = $this->push(
			$store,
			array(
				'operation' => 'delete',
				'payload' => null,
				'baseRevision' => 'sha256:stale',
			)
		);
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_conflict', $result->get_data()['code'] );
		$this->assertSame( $current, $result->get_data()['current'] );
		$this->assertSame( array(), $store->finalized );          // not finalized
		$this->assertSame( array( self::MID ), $store->released );  // conflict ⇒ reservation released
		// Crucially: only the current-state GET ran — NO DELETE was issued.
		$calls = $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array();
		$this->assertCount( 1, $calls );
		$this->assertSame( 'GET', $calls[0]->get_method() );
	}

	public function test_delete_with_matching_base_revision_proceeds(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 7001;
		$payload = array(
			'id' => 7001,
			'status' => 'processing',
			'total' => '10.00',
		);
		$revision = Order_Serializer::canonical_revision( $payload );
		$this->setRestResponse( $payload, 200 );

		$result = $this->push(
			$store,
			array(
				'collection' => 'orders',
				'operation' => 'delete',
				'payload' => null,
				'baseRevision' => $revision,
			)
		);

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( array( self::MID => 7001 ), $store->finalized );
		$this->assertSame( array( 'GET', 'DELETE' ), array_map( static fn( $request ) => $request->get_method(), $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ) );
	}


	public function test_delete_of_existing_record_without_base_revision_is_rejected_428(): void {
		// An existing record must not be force-deleted without a precondition — the client
		// defaults deletes to a null baseRevision, so an unconditional delete is refused
		// (otherwise the F2 data-loss path stays open in practice).
		$store = new Fake_Mutation_Store();
		$store->resolve = 10;
		$result = $this->push(
			$store,
			array(
				'operation' => 'delete',
				'payload' => null,
			)
		); // baseRevision defaults to null
		$this->assertSame( 428, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_precondition_required', $result->get_data()['code'] );
		$this->assertSame( array(), $store->finalized );          // record NOT deleted
		$this->assertSame( array( self::MID ), $store->released );  // released so a retry-with-revision can re-claim
		// Refused before touching the record — no GET, no DELETE.
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	/** @dataProvider recordedCreateReplayStatuses */
	public function test_done_create_replays_with_its_recorded_response_status( int $recorded_status ): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 4242;
		$store->lookups[ self::MID ] = array(
			'remote_id' => 4242,
			'operation' => 'create',
			'record_uuid' => self::REC,
			'status' => 'done',
			'response_status' => $recorded_status,
			'fingerprint' => self::DEFAULT_FINGERPRINT,
		);
		$this->setRestResponse(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			200
		);
		$result = $this->push( $store );
		$this->assertSame( $recorded_status, $result->get_status() );
		$this->assertSame( 4242, $result->get_data()['document']['id'] );
		$this->assertSame( self::REC, $this->metaUuid( $result->get_data()['document'] ) ); // uuid reflected on replay too
		$this->assertSame( array(), $store->reserved ); // replay short-circuits BEFORE reserve
	}

	/**
	 * A stored mutationId replays ONLY for its own record and operation — a
	 * reused id targeting a different record or operation is a 422 envelope
	 * rejection, never a silent ack (codex increment-3 finding).
	 */
	public function test_replay_rejects_mutation_id_reused_for_another_record(): void {
		$store = new Fake_Mutation_Store();
		$store->lookups[ self::MID ] = array(
			'remote_id' => 4242,
			'operation' => 'create',
			'record_uuid' => '99999999-9999-4999-8999-999999999999',
			'status' => 'done',
			'response_status' => 201,
		);
		$result = $this->push( $store );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_bad_mutation_id', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( array(), $store->reserved );
	}

	public function test_replay_rejects_mutation_id_reused_for_another_operation(): void {
		$store = new Fake_Mutation_Store();
		$store->lookups[ self::MID ] = array(
			'remote_id' => 4242,
			'operation' => 'delete',
			'record_uuid' => self::REC,
			'status' => 'done',
			'response_status' => 200,
		);
		// The default push() envelope is a create — same record, different operation.
		$result = $this->push( $store );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_bad_mutation_id', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/** @dataProvider mutationFingerprintMismatches */
	public function test_replay_rejects_mutation_id_reused_with_a_different_envelope( array $override ): void {
		$store = new Fake_Mutation_Store();
		$this->setRestResponse(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			201
		);
		$this->assertSame( 201, $this->push( $store )->get_status() );
		$this->assertNotSame( '', $store->fingerprints[ self::MID ] );

		$result = $this->push( $store, $override );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woo_rxdb_sync_bad_mutation_id', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	public static function mutationFingerprintMismatches(): array {
		return array(
			'base revision' => array( array( 'baseRevision' => 'sha256:different' ) ),
			'payload' => array( array( 'payload' => array( 'email' => 'different@example.test' ) ) ),
			'collection' => array( array( 'collection' => 'products' ) ),
		);
	}

	public function test_reserve_loser_rechecks_fingerprint_before_reclaim(): void {
		$store = new Fake_Mutation_Store();
		$store->lookupResults = array(
			null,
			array(
				'collection' => 'customers',
				'remote_id' => 0,
				'operation' => 'create',
				'record_uuid' => self::REC,
				'status' => 'pending',
				'fingerprint' => str_repeat( 'f', 64 ),
			),
		);
		$store->reserveResults = array( false );
		$store->reclaimOk = true;

		$result = $this->push( $store );

		$this->assertSame( 'woo_rxdb_sync_bad_mutation_id', $result->get_error_code() );
		$this->assertSame( array( self::MID ), $store->reserved );
		$this->assertSame( array(), $store->finalized );
	}

	public function test_legacy_row_without_fingerprint_fails_closed(): void {
		$store = new Fake_Mutation_Store();
		$store->lookups[ self::MID ] = array(
			'collection' => 'customers',
			'remote_id' => 4242,
			'operation' => 'create',
			'record_uuid' => self::REC,
			'status' => 'done',
			'fingerprint' => '',
		);

		$result = $this->push( $store );

		$this->assertSame( 'woo_rxdb_sync_bad_mutation_id', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	public static function recordedCreateReplayStatuses(): array {
		return array(
			'accepted create' => array( 202 ),
			'applied create' => array( 201 ),
			'born-twice create' => array( 200 ),
		);
	}

	public function test_replay_410_when_uuid_no_longer_maps_to_recorded_id(): void {
		$store = new Fake_Mutation_Store();
		$store->resolve = 0; // the uuid no longer resolves to the recorded id
		$store->lookups[ self::MID ] = array(
			'remote_id' => 99,
			'operation' => 'create',
			'record_uuid' => self::REC,
			'status' => 'done',
			'fingerprint' => self::DEFAULT_FINGERPRINT,
		);
		$result = $this->push( $store );
		$this->assertSame( 'woo_rxdb_sync_orphaned_mutation', $result->get_error_code() );
	}

	public function test_concurrent_reserve_lost_and_not_yet_done_reports_in_progress_409(): void {
		$store = new Fake_Mutation_Store();
		$store->reserveResults = array( false ); // another request holds the in-flight reservation
		$store->reclaimOk = false; // not stale
		$result = $this->push( $store );
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_in_progress', $result->get_data()['code'] );
		$this->assertSame( array(), $store->finalized );
	}

	public function test_reserve_lost_pending_create_is_not_reclaimed(): void {
		$store = new Fake_Mutation_Store();
		$store->reserveResults = array( false );
		$store->reclaimOk = true;
		$this->setRestResponse(
			array(
				'id' => 500,
				'email' => 'a@b.c',
			),
			201
		);
		$result = $this->push( $store );
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_in_progress', $result->get_data()['code'] );
		$this->assertSame( array(), $store->finalized );
		$this->assertSame( array( self::MID ), $store->reserved );
		$this->assertEmpty( $GLOBALS['wcpos_sync_test_rest_do_request_calls'] ?? array() );
	}

	private function pushWithHeaders( Fake_Mutation_Store $store, array $headers, array $envOver = array() ) {
		$request = $this->request( $this->envelope( $envOver ) );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return ( new Write_Controller( $store ) )->push( $request );
	}

	public function test_matching_mirror_header_passes_through_to_normal_handling(): void {
		// Idempotency-Key == body mutationId → mirror passes, so the create runs (201), NOT a 422 mismatch.
		$store = new Fake_Mutation_Store();
		$this->setRestResponse(
			array(
				'id' => 4242,
				'email' => 'a@b.c',
			),
			201
		);
		$result = $this->pushWithHeaders( $store, array( 'Idempotency-Key' => self::MID ) );
		$this->assertInstanceOf( 'WP_REST_Response', $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( array( self::MID ), $store->reserved );
	}

	public function test_diverging_idempotency_key_header_returns_422_before_reserving(): void {
		$store = new Fake_Mutation_Store();
		$result = $this->pushWithHeaders( $store, array( 'Idempotency-Key' => '99999999-9999-4999-8999-999999999999' ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woo_rxdb_sync_header_body_mismatch', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( array(), $store->reserved ); // rejected before any reservation
	}

	public function test_diverging_if_match_header_returns_422(): void {
		$store = new Fake_Mutation_Store();
		$result = $this->pushWithHeaders(
			$store,
			array( 'If-Match' => '"rev-other"' ),
			array(
				'operation' => 'update',
				'baseRevision' => 'rev-1',
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woo_rxdb_sync_header_body_mismatch', $result->get_error_code() );
		$this->assertSame( array(), $store->reserved );
	}

	public function test_weak_and_quoted_if_match_is_unquoted_before_compare(): void {
		// W/"rev-1" must unquote to rev-1 and MATCH the body baseRevision → mirror passes, so the request
		// reaches the normal update path (404 record-not-found here), NOT a false 422.
		$store = new Fake_Mutation_Store(); // resolve=0 → update finds no record
		$result = $this->pushWithHeaders(
			$store,
			array( 'If-Match' => 'W/"rev-1"' ),
			array(
				'operation' => 'update',
				'baseRevision' => 'rev-1',
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woo_rxdb_sync_record_not_found', $result->get_error_code() );
	}
}
