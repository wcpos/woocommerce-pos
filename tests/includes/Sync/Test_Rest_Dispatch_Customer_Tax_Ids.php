<?php
/**
 * Route-dispatch pins for customer tax_ids on the v2 push (issue #1403 row 4).
 *
 * The v1 `Customers_Controller` persisted `tax_ids` via Tax_Id_Writer on create and
 * update and reflected the persisted list in the response. These pins drive the
 * REAL registered route (`POST /wcpos/v2/push/customers`, real inner wc/v3
 * forward, real Mutation_Store) and assert the same contract on v2.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.

use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Customer_Tax_Ids extends Sync_REST_Store_Test_Case {
	private const REC = '5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c6e';

	/** @var WP_REST_Request[] Captured inner wc/v3 customer writes. */
	private $forwarded = array();

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		// The #1391 parity filter (Customer_Meta_Parity, registered by Init during the
		// phpunit bootstrap) decorates the ack's re-read with tax_ids. It gates on
		// wcpos_request(), which reads the real HTTP header from the server globals —
		// set it exactly like Test_Catalog_Proxy_Customer_Meta_Parity.
		$_SERVER['HTTP_X_WCPOS'] = '1';
		$this->forwarded = array();
		add_filter( 'rest_pre_dispatch', array( $this, 'capture_wc_request' ), 1, 3 );
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		remove_filter( 'rest_pre_dispatch', array( $this, 'capture_wc_request' ), 1 );
		parent::tearDown();
	}

	/** Capture-only: the inner wc/v3 dispatch stays real. */
	public function capture_wc_request( $result, $server, WP_REST_Request $request ) {
		if ( 0 === strpos( $request->get_route(), '/wc/v3/customers' ) && 'GET' !== $request->get_method() ) {
			$this->forwarded[] = $request;
		}
		return $result;
	}

	private function push_envelope( array $envelope ) {
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/customers' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	private function create_envelope( array $payload_over = array(), string $mutation = 'a1b2c3d4-4444-4222-8333-000000000001' ): array {
		return array(
			'mutationId'   => $mutation,
			'operation'    => 'create',
			'collection'   => 'customers',
			'recordId'     => self::REC,
			'baseRevision' => null,
			'payload'      => array_merge(
				array(
					'email'     => 'tax-ids-pin@example.test',
					'meta_data' => array(
						array(
							'key'   => '_woocommerce_pos_uuid',
							'value' => self::REC,
						),
					),
				),
				$payload_over
			),
		);
	}

	/** The baseRevision the conflict check recomputes: document_for parity. */
	private function current_revision( int $user_id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/customers/' . $user_id ) );

		return Revision::compute( Meta_Normalizer::normalize( $response->get_data() ) );
	}

	public function test_customer_create_persists_tax_ids_and_acks_them(): void {
		$tax_ids = array(
			array(
				'type'    => 'au_abn',
				'value'   => '51824753556',
				'country' => 'AU',
			),
		);

		$response = $this->push_envelope( $this->create_envelope( array( 'tax_ids' => $tax_ids ) ) );

		$this->assertSame( 201, $response->get_status() );
		$user_id   = (int) $response->get_data()['document']['id'];
		$persisted = ( new Tax_Id_Reader() )->read_for_user( $user_id );
		$this->assertCount( 1, $persisted );
		$this->assertSame( 'au_abn', $persisted[0]['type'] );
		$this->assertSame( '51824753556', $persisted[0]['value'] );
		$this->assertSame( 'AU', $persisted[0]['country'] );
		// The ack document reflects the persisted list (v1 response parity).
		$ack = $response->get_data()['document']['tax_ids'] ?? array();
		$this->assertSame( '51824753556', $ack[0]['value'] ?? null );
	}

	public function test_customer_update_persists_tax_ids(): void {
		$created = $this->push_envelope( $this->create_envelope() );
		$this->assertSame( 201, $created->get_status() );
		$user_id = (int) $created->get_data()['document']['id'];

		$response = $this->push_envelope(
			array(
				'mutationId'   => 'a1b2c3d4-4444-4222-8333-000000000002',
				'operation'    => 'update',
				'collection'   => 'customers',
				'recordId'     => self::REC,
				'baseRevision' => $this->current_revision( $user_id ),
				'payload'      => array(
					'first_name' => 'Pinned',
					'tax_ids'    => array(
						array(
							'type'    => 'gb_vat',
							'value'   => 'GB123456789',
							'country' => 'GB',
						),
					),
					'meta_data'  => array(
						array(
							'key'   => '_woocommerce_pos_uuid',
							'value' => self::REC,
						),
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$persisted = ( new Tax_Id_Reader() )->read_for_user( $user_id );
		$this->assertSame( 'gb_vat', $persisted[0]['type'] ?? null );
		$this->assertSame( 'GB123456789', $persisted[0]['value'] ?? null );
		$this->assertSame( 'Pinned', get_user_meta( $user_id, 'first_name', true ) );
		$ack = $response->get_data()['document']['tax_ids'] ?? array();
		$this->assertSame( 'GB123456789', $ack[0]['value'] ?? null );
	}

	public function test_customer_create_announces_tax_ids_after_persistence(): void {
		$observed_tax_ids = array();
		$observer         = static function ( int $customer_id ) use ( &$observed_tax_ids ): void {
			$observed_tax_ids[] = ( new Tax_Id_Reader() )->read_for_user( $customer_id );
		};
		add_action( 'woocommerce_update_customer', $observer, 20, 1 );

		try {
			$response = $this->push_envelope(
				$this->create_envelope(
					array(
						'tax_ids' => array(
							array(
								'type'    => 'au_abn',
								'value'   => '51824753556',
								'country' => 'AU',
							),
						),
					),
					'a1b2c3d4-4444-4222-8333-000000000006'
				)
			);
		} finally {
			remove_action( 'woocommerce_update_customer', $observer, 20 );
		}

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $observed_tax_ids );
		$latest = end( $observed_tax_ids );
		$this->assertSame( '51824753556', $latest[0]['value'] ?? null );
	}

	public function test_customer_create_rejects_malformed_tax_ids_before_the_forward(): void {
		$response = $this->push_envelope(
			$this->create_envelope(
				array( 'tax_ids' => array( array( 'type' => 'not_a_type' ) ) ),
				'a1b2c3d4-4444-4222-8333-000000000003'
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_invalid_tax_ids', $response->get_data()['code'] );
		// Rejected BEFORE the wc/v3 forward: no customer write dispatched, no user created.
		$this->assertSame( array(), $this->forwarded );
		$this->assertFalse( get_user_by( 'email', 'tax-ids-pin@example.test' ) );
	}

	public function test_customer_update_rejects_malformed_tax_ids_before_the_forward(): void {
		$created = $this->push_envelope( $this->create_envelope() );
		$this->assertSame( 201, $created->get_status() );
		$user_id         = (int) $created->get_data()['document']['id'];
		$this->forwarded = array();

		$response = $this->push_envelope(
			array(
				'mutationId'   => 'a1b2c3d4-4444-4222-8333-000000000004',
				'operation'    => 'update',
				'collection'   => 'customers',
				'recordId'     => self::REC,
				'baseRevision' => $this->current_revision( $user_id ),
				'payload'      => array(
					'first_name' => 'MustNotLand',
					'tax_ids'    => array( array( 'value' => 'no-type-at-all' ) ),
					'meta_data'  => array(
						array(
							'key'   => '_woocommerce_pos_uuid',
							'value' => self::REC,
						),
					),
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_invalid_tax_ids', $response->get_data()['code'] );
		$this->assertSame( array(), $this->forwarded );
		$this->assertNotSame( 'MustNotLand', get_user_meta( $user_id, 'first_name', true ) );
	}

	public function test_customer_forward_does_not_carry_tax_ids_to_wc(): void {
		$response = $this->push_envelope(
			$this->create_envelope(
				array(
					'tax_ids' => array(
						array(
							'type'  => 'au_abn',
							'value' => '51824753556',
						),
					),
				),
				'a1b2c3d4-4444-4222-8333-000000000005'
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $this->forwarded );
		foreach ( $this->forwarded as $request ) {
			$this->assertArrayNotHasKey( 'tax_ids', $request->get_body_params() );
		}
	}
}
