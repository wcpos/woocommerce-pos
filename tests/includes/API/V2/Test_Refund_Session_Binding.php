<?php
/**
 * Refund-session binding through the current REST lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Refunds charge their own session, never the sale's session. */
class Test_Refund_Session_Binding extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture {
		tearDown as fixture_tear_down;
	}
	use HPOSToggleTrait;

	/** Orders whose unstamped records need cleanup too.
	 *
	 * @var array
	 */
	private $order_ids = array();

	/** Whether this case switched order storage to HPOS.
	 *
	 * @var bool
	 */
	private $hpos_enabled = false;

	/** Restore order storage and clean committed, unstamped records. */
	public function tearDown(): void {
		// The closure write commits the test transaction, which makes the HPOS-on option
		// durable; switching it off must happen AFTER the framework's rollback and be
		// committed below, or the database keeps HPOS on for every later test.
		$this->fixture_tear_down();
		if ( $this->hpos_enabled ) {
			// The framework's teardown restored the hook table, so re-allow the switch.
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->hpos_enabled = false;
		}
		global $wpdb;
		foreach ( $this->order_ids as $id ) {
			$wpdb->delete( ( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->table_name(), array( 'order_id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
	}

	/** Exercise both refund meta storage paths. */
	public function storage_modes(): array {
		return array(
			'posts' => array( false ),
			'hpos' => array( true ),
		);
	}

	/** Grant closure capabilities and restore the singleton's WooCommerce observer. */
	public function setUp(): void {
		add_filter(
			'woocommerce_pos_payment_gateways_settings',
			static function ( $settings ) {
				$settings['gateways']['pos_card']['enabled'] = true;
				return $settings;
			}
		);
		parent::setUp();
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_closures' );
		add_action( 'woocommerce_order_refunded', array( Fiscal_Record_Writers::instance(), 'handle_refund' ), 10, 2 );
		add_action( 'woocommerce_pos_refund_allocated', array( Fiscal_Record_Writers::instance(), 'handle_allocation' ), 10, 4 );
	}

	/** Switch order storage to HPOS for this case, restored in tearDown. */
	private function enable_hpos(): void {
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->hpos_enabled = true;
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/** Seed one captured cash sale through the payment route.
	 *
	 * @param array  $session Session fixture.
	 * @param string $method Tender method.
	 */
	private function sale( array $session, string $method = 'pos_cash' ): array {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_total( '50' );
		$order->update_meta_data( '_wcpos_session', $session['id'] );
		$order->update_meta_data( '_wcpos_register', $session['register_id'] );
		$order->save();
		$this->order_ids[] = $order->get_id();
		$id = wp_generate_uuid4();
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
		$request->set_body_params(
			array(
				'payment' => array(
					'id' => $id,
					'method_id' => $method,
					'amount' => '50',
					'session_id' => $session['id'],
				),
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return array( $order, $id );
	}

	/** Simulate the Pro stamp before WooCommerce saves the refund.
	 *
	 * @param \WC_Order  $order Parent order.
	 * @param array|null $session Refund session, or no stamp.
	 */
	private function refund( \WC_Order $order, ?array $session ): \WC_Order_Refund {
		$stamp = static function ( $refund ) use ( $session ) {
			if ( $session ) {
				$refund->update_meta_data( '_wcpos_session', strtoupper( $session['id'] ) );
				$refund->update_meta_data( '_wcpos_register', strtoupper( $session['register_id'] ) );
			}
		};
		add_action( 'woocommerce_create_refund', $stamp );
		try {
			$refund = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount' => '10',
					'reason' => 'Damaged',
				)
			);
			$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
			return $refund;
		} finally {
			remove_action( 'woocommerce_create_refund', $stamp );
		}
	}

	/** Allocate after the refund observer has run.
	 *
	 * @param \WC_Order        $order Parent order.
	 * @param string           $payment Payment UUID.
	 * @param \WC_Order_Refund $refund Refund.
	 * @param string           $amount Allocation amount.
	 */
	private function allocate( \WC_Order $order, string $payment, \WC_Order_Refund $refund, string $amount = '10' ): void {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $payment . '/refund' );
		$request->set_body_params(
			array(
				'refund_id' => $refund->get_id(),
				'amount' => $amount,
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/** Read a session's derived cash.
	 *
	 * @param array $session Session fixture.
	 */
	private function cash( array $session ): string {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data()['expected']['cash'];
	}

	/** Close through the write lane.
	 *
	 * @param array $session Session fixture.
	 * @param int   $number Closure number.
	 */
	private function close( array $session, int $number = 1 ): array {
		$request = $this->wp_rest_post_request( '/wcpos/v2/closures' );
		$body = $this->closure_fields( $session, $number );
		foreach ( array( 'opened_at', 'closed_at', 'printed_at' ) as $key ) {
			$body[ $key ] = null === $body[ $key . '_gmt' ] ? null : str_replace( ' ', 'T', $body[ $key . '_gmt' ] ) . 'Z';
			unset( $body[ $key . '_gmt' ] );
		}
		$request->set_body_params( $body );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/** Read filtered records.
	 *
	 * @param array $filters Query filters.
	 */
	private function records( array $filters ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/records' );
		$request->set_query_params( $filters );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/** The refund session's store wins over both the register and sale store.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_cross_store_uses_session_store( bool $hpos ): void {
		// Arrange.
		if ( $hpos ) {
			$this->enable_hpos();
		}
		$register = ( new Register_Store() )->create(
			array(
				'name' => 'Refund store fixture',
				'store_id' => 456,
			)
		);
		$this->closure_registers[] = $register['id'];
		$session = ( new Register_Session_Store() )->create(
			array(
				'id' => wp_generate_uuid4(),
				'register_id' => $register['id'],
				'store_id' => 789,
				'opened_at_gmt' => '2026-09-11 08:00:00',
				'opened_by' => get_current_user_id(),
				'expected_float' => null,
				'counted_float' => '100',
			)
		);
		list( $order ) = $this->sale( $session );
		$order->update_meta_data( '_pos_store', 456 );
		$order->save();
		// Act.
		$this->refund( $order, $session );
		// Assert.
		$records = $this->records(
			array(
				'type' => 'refund',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( 789, $records[0]['store_id'] );
		// The frozen document identifies the refund's register, not the sale's.
		$this->assertSame( $register['id'], $records[0]['payload']['register']['id'] );
	}

	/** An allocation after closing records the tender shift, not another refund.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_allocation_after_closure_records_reallocation( bool $hpos ): void {
		// Arrange.
		if ( $hpos ) {
			$this->enable_hpos();
		}
		$session = $this->closure_session( null, 'open' );
		list( $order, $payment ) = $this->sale( $session, 'pos_card' );
		$refund = $this->refund( $order, $session );
		$session = ( new Register_Session_Store() )->transition(
			$session,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
		$closure = $this->close( $session );
		$this->assertSame(
			array(
				'cash' => '90.0000',
				'card' => '50.0000',
			),
			$closure['expected']
		);
		$this->assertSame( '10.0000', $closure['period_refunds_total'] );
		$this->assertSame( $closure['id'], wc_get_order( $refund->get_id() )->get_meta( '_wcpos_closure' ) );
		// Act.
		$this->allocate( $order, $payment, $refund );
		// Assert.
		$records = $this->records(
			array(
				'type' => 'late_refund',
				'closure_id' => $closure['id'],
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( 'reallocation', $records[0]['payload']['kind'] );
		$this->assertSame( '10.0000', $records[0]['payload']['expected_delta']['cash'] );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/closures/' . $closure['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['corrections'] );
		$this->assertEquals(
			array(
				'card' => '-10.0000',
				'cash' => '10.0000',
			),
			$data['corrections'][0]['figures']['expected_delta']
		);
		$this->assertSame( '0.0000', $data['corrections'][0]['figures']['refunds_delta'] );
		$this->assertSame( $closure['expected'], $data['expected'] );
		$this->assertSame( $closure['period_refunds_total'], $data['period_refunds_total'] );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'cash' => '100.0000',
				'card' => '40.0000',
			),
			$response->get_data()['expected']
		);
	}

	/** A cash-kind allocation after closing is no shift and records nothing.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_cash_allocation_after_closure_records_nothing( bool $hpos ): void {
		// Arrange.
		if ( $hpos ) {
			$this->enable_hpos();
		}
		$session = $this->closure_session( null, 'open' );
		list( $order, $payment ) = $this->sale( $session );
		$refund = $this->refund( $order, $session );
		$session = ( new Register_Session_Store() )->transition(
			$session,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
		$closure = $this->close( $session );
		$this->assertSame( '140.0000', $closure['expected']['cash'] );
		// Act.
		$this->allocate( $order, $payment, $refund );
		// Assert.
		$this->assertSame(
			array(),
			$this->records(
				array(
					'type' => 'late_refund',
					'closure_id' => $closure['id'],
				)
			)
		);
		$this->assertSame( '140.0000', $this->cash( $session ) );
	}

	/** A pending allocation has moved no money and records nothing until it succeeds. */
	public function test_refund_pending_allocation_after_closure_records_nothing(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		list( $order, $payment ) = $this->sale( $session, 'pos_card' );
		$refund = $this->refund( $order, $session );
		$session = ( new Register_Session_Store() )->transition(
			$session,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
		$closure = $this->close( $session );
		// The observer gets the refund as the ledger reloads it, after the closure stamped it.
		$refund = wc_get_order( $refund->get_id() );
		$row = array(
			'id' => $payment,
			'method_id' => 'pos_card',
			'kind' => 'card',
			'refunds' => array(
				array(
					'id' => $refund->get_id(),
					'amount' => '10.00',
					'status' => 'pending',
				),
			),
		);
		$filters = array(
			'type' => 'late_refund',
			'closure_id' => $closure['id'],
		);
		// Act.
		Fiscal_Record_Writers::instance()->handle_allocation( $order, $row, $refund, '10.00' );
		// Assert.
		$this->assertSame( array(), $this->records( $filters ) );
		$row['refunds'][0]['status'] = 'succeeded';
		Fiscal_Record_Writers::instance()->handle_allocation( $order, $row, $refund, '10.00' );
		$this->assertCount( 1, $this->records( $filters ) );
		$this->assertSame( 'reallocation', $this->records( $filters )[0]['payload']['kind'] );
	}

	/** An allocation recovers a late-arrival record whose insert was lost, and writes no reallocation. */
	public function test_refund_allocation_recovers_a_missing_late_arrival_record(): void {
		// Arrange: a late refund whose late-arrival record is gone.
		$session = $this->closure_session();
		list( $order, $payment ) = $this->sale( $session, 'pos_card' );
		$closure = $this->close( $session );
		$refund = $this->refund( $order, $session );
		$filters = array(
			'type' => 'late_refund',
			'closure_id' => $closure['id'],
		);
		$lost = $this->records( $filters );
		$this->assertCount( 1, $lost );
		global $wpdb;
		$wpdb->delete( ( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->table_name(), array( 'id' => $lost[0]['id'] ) );
		$this->assertSame( array(), $this->records( $filters ) );
		// Act.
		$this->allocate( $order, $payment, $refund );
		// Assert: the late arrival is back, projected live; no reallocation was written.
		$records = $this->records( $filters );
		$this->assertCount( 1, $records );
		$this->assertArrayNotHasKey( 'kind', $records[0]['payload'] );
		// Recovered after the card allocation landed, so the frozen delta already shows no cash.
		$this->assertSame( '0.0000', $records[0]['payload']['expected_delta']['cash'] );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/closures/' . $closure['id'] ) );
		$this->assertSame(
			array(
				'cash' => '0.0000',
				'card' => '-10.0000',
			),
			$response->get_data()['corrections'][0]['figures']['expected_delta']
		);
	}

	/** Same-session allocation is counted exactly once.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_same_session_subtracts_once( bool $hpos ): void {
		if ( $hpos ) {
			$this->enable_hpos();
		}
		// Arrange.
		$session = $this->closure_session();
		list( $order, $payment ) = $this->sale( $session );
		// Act.
		$refund = $this->refund( $order, $session );
		$this->allocate( $order, $payment, $refund );
		// Assert.
		$this->assertSame( '140.0000', $this->cash( $session ) );
		$closure = $this->close( $session );
		$this->assertSame( '140.0000', $closure['expected']['cash'] );
		$this->assertSame( '10.0000', $closure['period_refunds_total'] );
		$this->assertSame( '10.0000', $closure['perpetual_refunds_total'] );
		$this->assertSame(
			array(),
			$this->records(
				array(
					'type' => 'late_refund',
					'session_id' => $session['id'],
				)
			)
		);
	}

	/** Returning an earlier sale charges only today's session.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_cross_session_preserves_original_drawer( bool $hpos ): void {
		if ( $hpos ) {
			$this->enable_hpos();
		}
		// Arrange.
		$first = $this->closure_session();
		list( $order, $payment ) = $this->sale( $first );
		$closure = $this->close( $first );
		$this->assertSame( '150.0000', $closure['expected']['cash'] );
		$this->assertSame( '0.0000', $closure['period_refunds_total'] );
		$second = $this->closure_session( $first['register_id'] );
		// Act.
		$refund = $this->refund( $order, $second );
		$this->allocate( $order, $payment, $refund );
		// Assert.
		$this->assertSame( '90.0000', $this->cash( $second ) );
		$this->assertSame( '150.0000', $this->cash( $first ) );
		$next = $this->close( $second, 2 );
		$this->assertSame( '90.0000', $next['expected']['cash'] );
		$this->assertSame( '10.0000', $next['period_refunds_total'] );
		$this->assertSame( '10.0000', $next['perpetual_refunds_total'] );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/closures/' . $closure['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $closure + array( 'corrections' => array() ), $response->get_data() );
		$this->assertSame( array(), $response->get_data()['corrections'] );
		$this->assertSame(
			array(),
			$this->records(
				array(
					'type' => 'late_refund',
					'order_id' => $order->get_id(),
				)
			)
		);
	}

	/** A late refund is projected without changing frozen closure figures.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_closed_session_creates_one_correction( bool $hpos ): void {
		if ( $hpos ) {
			$this->enable_hpos();
		}
		// Arrange.
		$session = $this->closure_session();
		list( $order, $payment ) = $this->sale( $session );
		$closure = $this->close( $session );
		// Act.
		$refund = $this->refund( $order, $session );
		$this->allocate( $order, $payment, $refund );
		$this->assertCount(
			1,
			$this->records(
				array(
					'type' => 'late_refund',
					'closure_id' => $closure['id'],
				)
			)
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replay WooCommerce's lifecycle event.
		do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
		// Assert.
		$records = $this->records(
			array(
				'type' => 'late_refund',
				'closure_id' => $closure['id'],
				'session_id' => $session['id'],
			)
		);
		$this->assertCount( 1, $records );
		$original = $this->records(
			array(
				'type' => 'refund',
				'session_id' => $session['id'],
			)
		);
		$this->assertCount( 1, $original );
		$this->assertSame( $session['register_id'], $original[0]['register_id'] );
		$this->assertSame( $original[0]['id'], $records[0]['corrects_record_id'] );
		$this->assertSame( $closure['id'], $records[0]['closure_id'] );
		$this->assertSame( (int) $refund->get_refunded_by(), $records[0]['cashier_id'] );
		$this->assertSame( 'Damaged', $records[0]['payload']['reason'] );
		$this->assertSame( '10.0000', $records[0]['payload']['amount'] );
		$this->assertSame( '-10.0000', $records[0]['payload']['expected_delta']['cash'] );
		$this->assertSame( '10.0000', $records[0]['payload']['variance_delta']['cash'] );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/closures/' . $closure['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['corrections'] );
		$this->assertSame( 'late_refund', $data['corrections'][0]['type'] );
		$this->assertSame( 'Damaged', $data['corrections'][0]['reason'] );
		$this->assertSame( '-10.0000', $data['corrections'][0]['figures']['expected_delta']['cash'] );
		$this->assertSame( '10.0000', $data['corrections'][0]['figures']['refunds_delta'] );
		$this->assertSame( $closure['expected'], $data['expected'] );
		$this->assertSame( $closure['period_refunds_total'], $data['period_refunds_total'] );
	}

	/** Unstamped refunds have no drawer, even when their parent does.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_no_session_leaves_drawer_unchanged( bool $hpos ): void {
		if ( $hpos ) {
			$this->enable_hpos();
		}
		// Arrange.
		$session = $this->closure_session();
		list( $order, $payment ) = $this->sale( $session );
		// Act.
		$refund = $this->refund( $order, null );
		$this->allocate( $order, $payment, $refund );
		// Assert.
		$records = $this->records(
			array(
				'type' => 'refund',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertNull( $records[0]['session_id'] );
		$this->assertNull( $records[0]['register_id'] );
		$this->assertSame( '150.0000', $this->cash( $session ) );
		$closure = $this->close( $session );
		$this->assertSame( '0.0000', $closure['period_refunds_total'] );
		$this->assertSame(
			array(),
			$this->records(
				array(
					'type' => 'late_refund',
					'order_id' => $order->get_id(),
				)
			)
		);
	}
	/** Later card allocation replaces the frozen cash assumption in the read projection.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_later_allocation_projects_live_tenders( bool $hpos ): void {
		// Arrange.
		if ( $hpos ) {
			$this->enable_hpos();
		}
		$session = $this->closure_session();
		list( $order, $payment ) = $this->sale( $session, 'pos_card' );
		$closure = $this->close( $session );
		$refund = $this->refund( $order, $session );
		// Act.
		$this->allocate( $order, $payment, $refund, '3.25' );
		// Assert.
		$records = $this->records(
			array(
				'type' => 'late_refund',
				'closure_id' => $closure['id'],
			)
		);
		$this->assertSame( '-10.0000', $records[0]['payload']['expected_delta']['cash'] );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/closures/' . $closure['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$figures = $response->get_data()['corrections'][0]['figures'];
		$this->assertSame(
			array(
				'cash' => '-6.7500',
				'card' => '-3.2500',
			),
			$figures['expected_delta']
		);
		$this->assertSame( '10.0000', $figures['refunds_delta'] );
		$this->assertSame( '93.2500', $this->cash( $session ) );
	}
}
