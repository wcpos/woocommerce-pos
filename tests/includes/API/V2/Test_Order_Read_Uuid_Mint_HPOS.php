<?php
/**
 * An order read that mints a uuid never saves the order, on HPOS.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Order;
use WCPOS\WooCommercePOS\API\Controller_Registry;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_HPOS_Unit_Test_Case;

/**
 * Reading an order is not a write to it.
 *
 * The first read of an order without a POS uuid mints one and persists it. On
 * HPOS, WooCommerce turns a meta write into a full `$order->save()` whenever the
 * order's `date_modified` is older than the current second
 * (`OrdersTableDataStore::after_meta_change()`), so the mint moved
 * `date_modified` and ran every save listener — including the v1 lane's
 * `_pos_user` fill, which attributed a web order to whichever cashier read it
 * and moved the order revision (the till's next money command answered 409
 * `wcpos_revision_stale`). In the suite the fixture is usually saved in the same
 * second as the read, so the full save was skipped and the bug flaked; every
 * case here backdates the fixture so the full-save branch is always taken.
 */
class Test_Order_Read_Uuid_Mint_HPOS extends WCPOS_REST_HPOS_Unit_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS and the production sync read lane.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
		$this->install_sync_read_lane();
	}

	/**
	 * Disable HPOS after each case.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		$this->uninstall_sync_read_lane();
	}

	/**
	 * The v2 read lane mints the uuid as meta only.
	 */
	public function test_v2_order_read_mints_a_uuid_without_saving_the_order(): void {
		// Arrange.
		$order    = $this->create_web_order_modified_a_while_ago();
		$modified = $order->get_date_modified()->getTimestamp();

		// Act.
		$row = $this->read_v2_order_row( $order );

		// Assert.
		$this->assert_minted_without_a_save( $order, $modified );
		$this->assertContains( '_woocommerce_pos_uuid', array_column( $row['meta_data'], 'key' ), 'the v2 row is served with the minted uuid' );
	}

	/**
	 * The v1 read lane mints the uuid as meta only, so its `_pos_user` fill never runs.
	 */
	public function test_v1_order_read_mints_a_uuid_without_saving_or_attributing_the_order(): void {
		// Arrange.
		$order    = $this->create_web_order_modified_a_while_ago();
		$modified = $order->get_date_modified()->getTimestamp();

		// Act: a v1 read (1.11.x tills, the credential probe).
		$v1 = $this->server->dispatch( $this->wp_rest_get_request( '/' . Controller_Registry::V1_NAMESPACE . '/orders/' . $order->get_id() ) );

		// Assert the v1 mint before the till's own v2 read, which would otherwise mint it too.
		$this->assertSame( 200, $v1->get_status() );
		$this->assert_minted_without_a_save( $order, $modified );

		// Act: the till's own v2 read, in a later request.
		$row = $this->read_v2_order_row( $order );

		// Assert.
		$this->assertSame( '', (string) $this->meta_value( $row, '_pos_user' ), 'the v2 row carries no cashier for a web order' );
	}

	/**
	 * A save the v1 lane makes outside a POS write (the email route's billing
	 * update) never attributes a web order to the cashier.
	 */
	public function test_v1_email_billing_save_does_not_attribute_a_web_order(): void {
		// Arrange.
		$order = $this->create_web_order_modified_a_while_ago();
		$this->read_v2_order_row( $order ); // The till has the order on screen.

		// Act.
		$request = $this->wp_rest_post_request( '/' . Controller_Registry::V1_NAMESPACE . '/orders/' . $order->get_id() . '/email' );
		$request->set_body_params(
			array(
				'email'   => 'receipt@example.com',
				'save_to' => 'billing',
			)
		);
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'receipt@example.com', $fresh->get_billing_email() );
		$this->assertSame( '', $fresh->get_meta( '_pos_user' ), 'an email save records no cashier' );
		$this->assertSame( '', $fresh->get_meta( '_pos_user_created' ), 'an email save records no creator' );
	}

	/**
	 * A till edit of a web order still records the cashier who made it.
	 */
	public function test_v1_update_of_a_web_order_records_the_cashier(): void {
		// Arrange.
		$order = $this->create_web_order_modified_a_while_ago();
		$this->read_v2_order_row( $order );

		// Act.
		$request = $this->wp_rest_post_request( '/' . Controller_Registry::V1_NAMESPACE . '/orders/' . $order->get_id() );
		$request->set_body_params( array( 'customer_note' => 'edited at the till' ) );
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'edited at the till', $fresh->get_customer_note() );
		$this->assertSame( (string) $this->user, $fresh->get_meta( '_pos_user' ) );
		$this->assertSame( (string) $this->user, $fresh->get_meta( '_pos_user_created' ) );
	}

	/**
	 * A web order with no POS uuid and no cashier, last modified ten minutes ago.
	 *
	 * The backdate is what makes WooCommerce's full-save branch deterministic: it
	 * only runs when `date_modified` is older than the current second.
	 *
	 * @return WC_Order
	 */
	private function create_web_order_modified_a_while_ago(): WC_Order {
		$order = OrderHelper::create_order();
		$order->set_date_modified( time() - 10 * MINUTE_IN_SECONDS );
		$order->save();

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( Pos_Uuid::META_KEY ), 'the fixture must start without a uuid, or nothing is minted' );
		$this->assertSame( '', $order->get_meta( '_pos_user' ) );
		$this->assertLessThan( time() - MINUTE_IN_SECONDS, $order->get_date_modified()->getTimestamp(), 'the fixture must be backdated' );

		return $order;
	}

	/**
	 * The uuid was persisted, and nothing else about the order moved.
	 *
	 * @param WC_Order $order    The order that was read.
	 * @param int      $modified Its `date_modified` timestamp before the read.
	 */
	private function assert_minted_without_a_save( WC_Order $order, int $modified ): void {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertTrue( Pos_Uuid::is_uuid( $fresh->get_meta( Pos_Uuid::META_KEY ) ), 'the read persisted a uuid' );
		$this->assertSame( $modified, $fresh->get_date_modified()->getTimestamp(), 'a read never moves date_modified' );
		$this->assertSame( '', $fresh->get_meta( '_pos_user' ), 'a read never records a cashier' );
		$this->assertSame( '', $fresh->get_meta( '_pos_user_created' ), 'a read never records a creator' );
	}

	/**
	 * Dispatch the till's v2 order read and return the single served row.
	 *
	 * @param WC_Order $order The order to read.
	 *
	 * @return array The served order payload.
	 */
	private function read_v2_order_row( WC_Order $order ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'include' => array( $order->get_id() ) ) );

		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $rows ) );
		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	/**
	 * The value of one meta key in a served row, or null when absent.
	 *
	 * @param array  $row The served order payload.
	 * @param string $key The meta key.
	 *
	 * @return mixed
	 */
	private function meta_value( array $row, string $key ) {
		foreach ( $row['meta_data'] as $meta ) {
			if ( $key === $meta['key'] ) {
				return $meta['value'];
			}
		}

		return null;
	}
}
