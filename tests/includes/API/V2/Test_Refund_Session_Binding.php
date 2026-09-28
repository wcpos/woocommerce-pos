<?php
/**
 * Refund-session binding through the current REST lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
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

	/** Restore order storage and clean committed, unstamped records. */
	public function tearDown(): void {
		$this->clean_up_cot_setup();
		$this->fixture_tear_down();
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
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_closures' );
		add_action( 'woocommerce_order_refunded', array( Fiscal_Record_Writers::instance(), 'handle_refund' ), 10, 2 );
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

	/** Same-session allocation is counted exactly once.
	 *
	 * @dataProvider storage_modes
	 * @param bool $hpos Use HPOS.
	 */
	public function test_refund_same_session_subtracts_once( bool $hpos ): void {
		if ( $hpos ) {
			$this->setup_cot();
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
			$this->setup_cot();
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
			$this->setup_cot();
		}
		// Arrange.
		$session = $this->closure_session();
		list( $order, $payment ) = $this->sale( $session );
		$closure = $this->close( $session );
		// Act.
		$refund = $this->refund( $order, $session );
		$this->allocate( $order, $payment, $refund );
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
			$this->setup_cot();
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
			$this->setup_cot();
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
