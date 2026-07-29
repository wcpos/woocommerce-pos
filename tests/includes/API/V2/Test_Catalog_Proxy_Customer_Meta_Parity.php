<?php
/**
 * Pins customer meta_data behavior on the V2 catalog proxy per role (#1309).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Pins what /wcpos/v2/customers serves per role TODAY (probed 2026-07-29,
 * evidence on #1309). Stock wc/v3 gates the entire meta_data key on
 * wc_current_user_has_role( 'administrator' ) and the proxy is a faithful
 * pass-through, so a cashier pull carries ONLY the uuid entry appended by
 * Proxy_Uuid_Stamper. V1's wcpos_customer_response() re-added non-protected
 * meta (and a top-level tax_ids field) for cashiers; V2 has no equivalent.
 *
 * These pins document CURRENT behavior, not a ruling: whether cashiers should
 * regain v1 meta/tax_ids parity is an open product question on #1309. If that
 * ruling lands, update these pins alongside the fix.
 */
class Test_Catalog_Proxy_Customer_Meta_Parity extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Customer carrying assorted meta: non-protected, protected, tax-ids-style.
	 *
	 * @var \WC_Customer
	 */
	private $customer;

	/**
	 * Enable sync routes, register uuid stampers, seed the customer fixture.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		Proxy_Uuid_Stamper::register_proxy_stampers();
		parent::setUp();

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
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
		delete_option( Api::OPTION_ENABLED );
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
	 * Admin pulls serve non-protected custom meta plus the stamped uuid;
	 * protected (underscore-prefixed) keys stay withheld — wc/v3's own
	 * is_protected_meta filter, unchanged by the proxy.
	 */
	public function test_admin_pull_serves_non_protected_meta_and_uuid(): void {
		$payload = $this->pull_customer();
		$keys    = $this->meta_keys( $payload );

		$this->assertContains( 'loyalty_points', $keys );
		$this->assertContains( Api::UUID_META_KEY, $keys );
		$this->assertNotContains( '_secret_internal', $keys );
		$this->assertNotContains( '_vat_number', $keys );
	}

	/**
	 * Cashier pulls are guaranteed the uuid entry ONLY — stock wc/v3 withholds
	 * the whole meta_data key from non-administrators and the proxy passes that
	 * through; the uuid is appended afterward by Proxy_Uuid_Stamper. The exact
	 * pin means non-uuid meta (loyalty_points here) is silently absent for
	 * cashiers — the v1-parity delta flagged for a product ruling on #1309.
	 */
	public function test_cashier_pull_is_guaranteed_uuid_only(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$payload = $this->pull_customer();

		$this->assertSame( array( Api::UUID_META_KEY ), $this->meta_keys( $payload ) );
		$uuid = $payload['meta_data'][0]['value'];
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			$uuid
		);
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
	 * V2 serves NO top-level tax_ids field to either role — v1 built it in
	 * wcpos_customer_response() via Tax_Id_Reader and nothing on the v2 proxy
	 * runs it. Pinned as current behavior; the read-side tax_ids gap is part of
	 * the same #1309 ruling.
	 */
	public function test_v2_pull_serves_no_tax_ids_field_for_any_role(): void {
		$this->assertArrayNotHasKey( 'tax_ids', $this->pull_customer() );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
		$this->assertArrayNotHasKey( 'tax_ids', $this->pull_customer() );
	}
}
