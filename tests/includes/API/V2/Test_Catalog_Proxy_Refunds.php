<?php
/**
 * Tests for the v2 refunds proxy read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order_Refund;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Cashier-facing refund reads through the production sync read lane.
 */
class Test_Catalog_Proxy_Refunds extends WCPOS_REST_Unit_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Whether this test enabled HPOS.
	 *
	 * @var bool
	 */
	private $hpos_enabled = false;

	/**
	 * Install the sync read lane and sign in a capability-only cashier.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->install_sync_read_lane();
		$cashier = wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
		// Explicitly revoke the capability inherited from the cashier role.
		$cashier->add_cap( 'read_private_shop_orders', false );
		$this->assertFalse( current_user_can( 'read_private_shop_orders' ) );
		$this->assertTrue( current_user_can( 'access_woocommerce_pos' ) );
	}

	/**
	 * Restore order storage and remove the sync read lane.
	 */
	public function tearDown(): void {
		if ( $this->hpos_enabled ) {
			$this->toggle_cot_feature_and_usage( false );
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
		parent::tearDown();
		$this->uninstall_sync_read_lane();
	}

	/**
	 * Create a refund with an explicit creation date.
	 *
	 * @param int    $order_id Parent order ID.
	 * @param string $date     Refund creation date in UTC.
	 * @return WC_Order_Refund
	 */
	private function create_refund( int $order_id, string $date = '2026-01-10T12:00:00Z' ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'order_id'     => $order_id,
				'amount'       => 1,
				'reason'       => 'Returned item',
				'date_created' => strtotime( $date ),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		return $refund;
	}

	/**
	 * Dispatch a successful refunds collection request.
	 *
	 * @param array $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function read( array $params = array() ): WP_REST_Response {
		$request = $this->wp_rest_get_request( '/wcpos/v2/refunds' );
		$request->set_query_params( $params );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response;
	}

	/**
	 * The cashier gets refund data and a revision, without UUID or digest stamping.
	 */
	public function test_refunds_cashier_lists_payload_without_minting_uuid(): void {
		$order  = OrderHelper::create_order();
		$refund = $this->create_refund( $order->get_id() );
		$refund->update_meta_data( 'refund_test_note', 'Till return' );
		$refund->save();

		$rows = $this->read( array( 'include' => array( $refund->get_id() ) ) )->get_data();

		$this->assertCount( 1, $rows );
		$row = $rows[0];
		foreach ( array( 'id', 'parent_id', 'amount', 'reason', 'date_created_gmt', 'meta_data', '_rxdb_revision' ) as $key ) {
			$this->assertArrayHasKey( $key, $row );
		}
		$this->assertSame( $refund->get_id(), $row['id'] );
		$this->assertSame( $order->get_id(), $row['parent_id'] );
		$this->assertSame( 1.0, (float) $row['amount'] );
		$this->assertSame( 'Returned item', $row['reason'] );
		$this->assertSame( '2026-01-10T12:00:00', $row['date_created_gmt'] );
		$this->assertNotEmpty( $row['_rxdb_revision'] );
		$meta = array_column( $row['meta_data'], 'value', 'key' );
		$this->assertSame( 'Till return', $meta['refund_test_note'] );
		$this->assertArrayNotHasKey( '_woocommerce_pos_uuid', $meta );
		$this->assertArrayNotHasKey( '_rxdb_digest', $row );
		$refund->read_meta_data( true );
		$this->assertSame( '', $refund->get_meta( '_woocommerce_pos_uuid' ) );
	}

	/**
	 * Date windows select refund creation dates, not the shared parent's date.
	 */
	public function test_refunds_date_windows_select_the_refund_creation_date(): void {
		$order   = OrderHelper::create_order();
		$earlier = $this->create_refund( $order->get_id(), '2026-01-10T12:00:00Z' );
		$later   = $this->create_refund( $order->get_id(), '2026-01-12T12:00:00Z' );
		$params  = array(
			'include'       => array( $earlier->get_id(), $later->get_id() ),
			'dates_are_gmt' => true,
		);

		$after  = $this->read( $params + array( 'after' => '2026-01-11T12:00:00' ) )->get_data();
		$before = $this->read( $params + array( 'before' => '2026-01-11T12:00:00' ) )->get_data();

		$this->assertSame( array( $later->get_id() ), array_column( $after, 'id' ) );
		$this->assertSame( array( $earlier->get_id() ), array_column( $before, 'id' ) );
	}

	/**
	 * Tied refund dates paginate in ascending ID order even for a descending walk.
	 */
	public function test_refunds_tied_dates_have_stable_pagination(): void {
		$order  = OrderHelper::create_order();
		$first  = $this->create_refund( $order->get_id() );
		$second = $this->create_refund( $order->get_id() );
		$params = array(
			'include'  => array( $first->get_id(), $second->get_id() ),
			'orderby'  => 'date',
			'order'    => 'desc',
			'per_page' => 1,
		);

		$page_one = $this->read( $params + array( 'page' => 1 ) )->get_data();
		$page_two = $this->read( $params + array( 'page' => 2 ) )->get_data();

		$this->assertSame( array( $first->get_id() ), array_column( $page_one, 'id' ) );
		$this->assertSame( array( $second->get_id() ), array_column( $page_two, 'id' ) );
	}

	/**
	 * Parent filtering restricts both rows and pagination totals on posts storage.
	 */
	public function test_refunds_parent_filter_returns_only_matching_order(): void {
		$this->assertFalse( OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->assert_parent_filter();
	}

	/**
	 * Parent filtering also applies when refunds are stored in HPOS.
	 */
	public function test_refunds_parent_filter_returns_only_matching_order_with_hpos(): void {
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->hpos_enabled = true;
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->assert_parent_filter();
	}

	/**
	 * Create fixtures in the active store and check the parent-filtered total.
	 */
	private function assert_parent_filter(): void {
		$order_a = OrderHelper::create_order();
		$order_b = OrderHelper::create_order();
		$first   = $this->create_refund( $order_a->get_id() );
		$second  = $this->create_refund( $order_a->get_id() );
		$other   = $this->create_refund( $order_b->get_id() );

		$response = $this->read(
			array(
				'parent'  => $order_a->get_id(),
				'include' => array( $first->get_id(), $second->get_id(), $other->get_id() ),
			)
		);

		$this->assertEqualsCanonicalizing( array( $first->get_id(), $second->get_id() ), array_column( $response->get_data(), 'id' ) );
		$this->assertSame( array( $order_a->get_id(), $order_a->get_id() ), array_column( $response->get_data(), 'parent_id' ) );
		$this->assertSame( 2, (int) $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * A logged-in user without POS access is forbidden.
	 */
	public function test_refunds_user_without_pos_access_gets_403(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'access_woocommerce_pos' ) );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/refunds' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Anonymous users must authenticate.
	 */
	public function test_refunds_anonymous_user_gets_401(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/refunds' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A completed proxy request does not grant access to raw WooCommerce reads.
	 */
	public function test_refunds_permission_hook_unwinds_after_proxy_request(): void {
		$this->read();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/refunds' ) );

		$this->assertSame( 403, $response->get_status() );
	}
}
