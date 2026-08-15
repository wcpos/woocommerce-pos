<?php
/**
 * Pins the v1-parity customer response contract on the V2 surfaces (#1309).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WCPOS\WooCommercePOS\Services\Customer_Meta_Parity;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Pins the #1309 ruling (2026-07-29): v2 customer serialization keeps v1.9
 * parity for POS requests. Stock wc/v3 withholds the entire meta_data key
 * from non-administrators and never serves tax_ids; Customer_Meta_Parity
 * restores both on every POS-marked wc/v3 customer serialization, so the
 * catalog proxy pulls AND the push write echo carry them for every role.
 *
 * Probe evidence for the pre-fix behavior (cashier = uuid-only meta,
 * tax_ids absent for all roles) is on #1309.
 */
class Test_Catalog_Proxy_Customer_Meta_Parity extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Customer carrying assorted meta: non-protected, protected, tax-ids-style.
	 *
	 * @var \WC_Customer
	 */
	private $customer;

	/**
	 * Register uuid stampers + the parity service (Init.php registrations do not
	 * run in the phpunit bootstrap), mark the request as POS, and seed the
	 * customer fixture.
	 */
	public function setUp(): void {
		Proxy_Uuid_Stamper::register_proxy_stampers();
		parent::setUp();
		new Customer_Meta_Parity();
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$this->customer = CustomerHelper::create_customer(
			array(
				'first_name' => 'Meta',
				'last_name'  => 'Parity',
				'email'      => 'meta.parity@example.com',
			)
		);
		$this->customer->add_meta_data( 'loyalty_points', '150', true );
		$this->customer->add_meta_data( '_secret_internal', 'hidden', true );
		$this->customer->add_meta_data( '_vat_number', 'GB123456789', true );
		$this->customer->save();
	}

	/**
	 * Remove sync state written outside the test transaction.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
	}

	/**
	 * Pull the fixture customer through the v2 proxy as the current user.
	 *
	 * @return array The served customer payload.
	 */
	private function pull_customer(): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params( array( 'include' => (string) $this->customer->get_id() ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertCount( 1, $data );

		return $data[0];
	}

	/**
	 * Extract meta keys from a served payload.
	 *
	 * @param array $payload Served customer payload.
	 *
	 * @return string[] Meta keys.
	 */
	private function meta_keys( array $payload ): array {
		return array_column( $payload['meta_data'] ?? array(), 'key' );
	}

	/**
	 * The v1-parity contract, asserted against one served payload: non-protected
	 * custom meta present (id/key/value shape), protected keys withheld, and the
	 * structured top-level tax_ids built from the legacy VAT meta.
	 *
	 * @param array $payload Served customer payload.
	 */
	private function assert_v1_parity_contract( array $payload ): void {
		$keys = $this->meta_keys( $payload );
		$this->assertContains( 'loyalty_points', $keys );
		$this->assertNotContains( '_secret_internal', $keys );
		$this->assertNotContains( '_vat_number', $keys );

		$loyalty = array_values(
			array_filter(
				$payload['meta_data'],
				static function ( $meta ) {
					return 'loyalty_points' === $meta['key'];
				}
			)
		);
		$this->assertSame( '150', $loyalty[0]['value'] );
		$this->assertIsInt( $loyalty[0]['id'] );

		$this->assertArrayHasKey( 'tax_ids', $payload );
		$this->assertCount( 1, $payload['tax_ids'] );
		$this->assertSame( 'gb_vat', $payload['tax_ids'][0]['type'] );
		$this->assertSame( 'GB123456789', $payload['tax_ids'][0]['value'] );
		$this->assertSame( 'GB', $payload['tax_ids'][0]['country'] );
	}

	/**
	 * Admin proxy pulls serve the full v1-parity contract plus the stamped uuid.
	 */
	public function test_admin_pull_serves_v1_parity_contract(): void {
		$payload = $this->pull_customer();

		$this->assert_v1_parity_contract( $payload );
		$this->assertContains( Api::UUID_META_KEY, $this->meta_keys( $payload ) );
	}

	/**
	 * Cashier proxy pulls serve the SAME contract — the #1309 regression:
	 * stock wc/v3 gates meta_data on the administrator role, so without
	 * Customer_Meta_Parity a cashier pull carried only the uuid entry.
	 */
	public function test_cashier_pull_serves_v1_parity_contract(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$payload = $this->pull_customer();

		$this->assert_v1_parity_contract( $payload );
		$this->assertContains( Api::UUID_META_KEY, $this->meta_keys( $payload ) );
	}

	/**
	 * The uuid served to a cashier is the SAME identity an admin pull serves —
	 * the guarantee the client keys on regardless of role.
	 */
	public function test_uuid_identity_is_role_independent(): void {
		$admin_payload = $this->pull_customer();
		$admin_uuid    = array_column( $admin_payload['meta_data'], 'value', 'key' )[ Api::UUID_META_KEY ];

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
		$cashier_payload = $this->pull_customer();
		$cashier_uuid    = array_column( $cashier_payload['meta_data'], 'value', 'key' )[ Api::UUID_META_KEY ];

		$this->assertSame( $admin_uuid, $cashier_uuid );
	}

	/**
	 * The write echo carries the contract too: a cashier updating a customer
	 * through wc/v3 (the dispatch /push/customers forwards to) must get
	 * meta_data + tax_ids back, or the client's local document would wipe
	 * those fields on every push round-trip.
	 */
	public function test_cashier_write_echo_serves_v1_parity_contract(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/customers/' . $this->customer->get_id() );
		$request->set_body_params( array( 'first_name' => 'Updated' ) );
		$response = $this->server->dispatch( $request );
		$payload  = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $payload ) );
		$this->assertSame( 'Updated', $payload['first_name'] );
		$this->assert_v1_parity_contract( $payload );
	}

	/**
	 * Non-POS wc/v3 traffic stays exactly stock: without the X-WCPOS marker a
	 * cashier gets no meta_data and nobody gets tax_ids — the parity fields
	 * must not leak outside POS requests.
	 */
	public function test_non_pos_request_keeps_stock_wc_behavior(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/customers/' . $this->customer->get_id() );
		$response = $this->server->dispatch( $request );
		$payload  = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $payload ) );
		$this->assertArrayNotHasKey( 'meta_data', $payload );
		$this->assertArrayNotHasKey( 'tax_ids', $payload );
	}
}
