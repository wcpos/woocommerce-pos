<?php
/**
 * Webview payment passthrough tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Webview payment passthrough tests. */
class Test_Webview_Passthrough extends WCPOS_REST_Unit_Test_Case {
	use \Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
	/**
	 * A contended mint must not interrupt WooCommerce's payment hooks.
	 *
	 * @dataProvider payment_hooks
	 */
	public function test_payment_hook_locked_ledger_logs_error_without_throwing( string $hook ): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->save();
		$before = $order->get_meta( Ledger::META_KEY, true );
		$logs = array();
		$log_filter = static function ( $enabled, $message ) use ( &$logs ) {
			$logs[] = array( Logger::$log_level, $message );
			return false;
		};
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		add_filter( 'woocommerce_pos_logging', $log_filter, 10, 2 );
		try {
			// Act: fire the hook directly so WooCommerce cannot swallow an escaped exception.
			$this->in_pos_request(
				static function () use ( $hook, $order ): void {
					do_action( 'woocommerce_before_pay_action', $order );
					do_action( $hook, $order->get_id(), 'pos-open', 'processing', $order );
				}
			);

			// Assert.
			$this->assertSame( $before, wc_get_order( $order->get_id() )->get_meta( Ledger::META_KEY, true ) );
			$this->assertContains(
				array( 'error', sprintf( 'WCPOS webview payment ledger could not be saved on order #%d: WCPOS payment ledger is locked.', $order->get_id() ) ),
				$logs
			);
		} finally {
			$lock->release( $order->get_id() );
			remove_filter( 'woocommerce_pos_logging', $log_filter, 10 );
			do_action( 'woocommerce_before_pay_action', null );
		}
	}

	/** Hook entry points that must contain ledger persistence errors. */
	public function payment_hooks(): array {
		return array(
			'payment complete' => array( 'woocommerce_payment_complete' ),
			'status changed' => array( 'woocommerce_order_status_changed' ),
		);
	}

	/** @dataProvider duplicate_payment_references */
	public function test_stale_callbacks_mint_one_payment( string $reference, bool $hpos ): void {
		if ( $hpos ) {
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->setup_cot();
			$this->toggle_cot_feature_and_usage( true );
			$this->disable_cot_sync();
		}
		try {
			$order = $this->create_order( true );
			$order->set_payment_method( 'pos_card' );
			$order->set_transaction_id( $reference );
			$order->save();
			$stale = wc_get_order( $order->get_id() );
			$this->assertSame( array(), Ledger::instance()->read( $stale ) );
			$first = \WCPOS\WooCommercePOS\Payments\Contract\Webview_Passthrough::mint( $order );
			$second = \WCPOS\WooCommercePOS\Payments\Contract\Webview_Passthrough::mint( $stale );
			$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
			$this->assertSame( $first['id'], $second['id'] );
		} finally {
			if ( $hpos ) {
				$this->toggle_cot_feature_and_usage( false );
				$this->clean_up_cot_setup();
				remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			}
		}
	}

	public function duplicate_payment_references(): array {
		return array( array( 'ch_duplicate', false ), array( '', false ), array( 'ch_duplicate', true ), array( '', true ) );
	}

	/** @dataProvider duplicate_payment_references */
	public function test_reopened_order_pay_mints_current_epoch_completion( string $reference, bool $hpos ): void {
		if ( $hpos ) {
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->setup_cot();
			$this->toggle_cot_feature_and_usage( true );
			$this->disable_cot_sync();
		}
		try {
			$order = $this->create_order( true );
			$uuid = wp_generate_uuid4();
			$order->update_meta_data( '_woocommerce_pos_uuid', $uuid );
			$order->set_payment_method( 'pos_card' );
			$order->save();
			$order->payment_complete( $reference );
			$order = wc_get_order( $order->get_id() );
			$this->assertSame( 0, Ledger::instance()->read( $order )[0]['epoch'] );

			$read = new \WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );
			$read->set_param( 'dp', '6' );
			$data = \WCPOS\WooCommercePOS\Sync\Meta_Normalizer::normalize( $this->server->dispatch( $read )->get_data() );
			$revision = \WCPOS\WooCommercePOS\Sync\Order_Serializer::canonical_revision( \WCPOS\WooCommercePOS\Sync\Order_Serializer::add_pos_links( $data, $order ) );
			$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'X-WCPOS-Client', 'ios/2.0.0 payments=1.3' );
			$request->set_body( wp_json_encode( array(
				'mutationId' => wp_generate_uuid4(), 'operation' => 'update', 'collection' => 'orders', 'recordId' => $uuid, 'baseRevision' => $revision, 'expectedEpoch' => 0,
				'payload' => array( 'status' => 'pos-open', 'meta_data' => array(
					array( 'key' => '_wcpos_money_epoch', 'value' => 1 ),
					array( 'key' => '_wcpos_completed_at', 'value' => null ),
					array( 'key' => '_wcpos_reopened_at', 'value' => '2026-10-19T12:00:00Z' ),
				) ),
			) ) );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$order = wc_get_order( $order->get_id() );
			$this->assertSame( 1, \WCPOS\WooCommercePOS\Payments\Contract\Money_Epoch::current( $order ) );
			$this->assertSame( 'pos-open', $order->get_status() );
			$this->assertFalse( \WCPOS\WooCommercePOS\Payments\Contract\Money_Epoch::is_completed_in_current_epoch( $order, Ledger::instance() ) );

			$order->payment_complete( $reference ? 'ch_next_epoch' : '' );
			$saved = wc_get_order( $order->get_id() );
			$rows = Ledger::instance()->read( $saved );
			$this->assertCount( 2, $rows );
			$this->assertSame( array( 0, 1 ), array_column( $rows, 'epoch' ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Payments\Contract\Money_Epoch::is_completed_in_current_epoch( $saved, Ledger::instance() ) );
		} finally {
			if ( $hpos ) {
				$this->toggle_cot_feature_and_usage( false );
				$this->clean_up_cot_setup();
				remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			}
		}
	}

	public function test_reopened_payment_reusing_transaction_id_mints_current_epoch_row(): void {
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->save();
		$order->payment_complete( 'reused_transaction' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 0, Ledger::instance()->read( $order )[0]['epoch'] );
		\WCPOS\WooCommercePOS\Payments\Contract\Money_Meta_Guard::authorised( $order->get_id(), static function () use ( $order ): void {
			$order->update_meta_data( '_wcpos_money_epoch', 1 );
			$order->set_status( 'pos-open' );
			$order->save();
		} );
		$order->payment_complete( 'reused_transaction' );
		$saved = wc_get_order( $order->get_id() );
		$rows = Ledger::instance()->read( $saved );
		$this->assertSame( array( 0, 1 ), array_column( $rows, 'epoch' ) );
		$this->assertTrue( \WCPOS\WooCommercePOS\Payments\Contract\Money_Epoch::is_completed_in_current_epoch( $saved, Ledger::instance() ) );
	}

	/** Payment completion mints a captured webview row. */
	public function test_payment_complete_on_pos_order_mints_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->set_transaction_id( 'ch_123' );
		$order->update_meta_data( '_pos_user', 123 );
		$order->save();

		// Act.
		$order->payment_complete();
		// The hook mints on its own wc_get_order() instance; re-read, as any later request would.
		$order = wc_get_order( $order->get_id() );
		$rows  = Ledger::instance()->read( $order );

		// Assert.
		$this->assertCount( 1, $rows );
		$this->assertSame( 'webview', $rows[0]['source'] );
		$this->assertSame( 'webview', $rows[0]['capture_mode'] );
		$this->assertSame( '92.95', $rows[0]['amount'] );
		$this->assertSame( 123, $rows[0]['cashier_id'] );
		$this->assertSame( 'ch_123', $rows[0]['provider_refs']['transaction_id'] );
		$this->assertSame( array( 'pos_card' ), array_values( wp_list_pluck( $order->get_meta( Ledger::INDEX_META_KEY, false ), 'value' ) ) );
	}

	/** Ledger-driven completion does not mint a duplicate webview row. */
	public function test_payment_complete_after_ledger_record_does_not_duplicate(): void {
		// Arrange: a live row that leaves the order short of its total, so the order is
		// still payable and payment_complete() actually fires the passthrough hook.
		$order = $this->create_order( true );
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '50.00',
			)
		);

		// Act.
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );

		// Assert.
		$rows = Ledger::instance()->read( $order );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pos_cash', $rows[0]['method_id'] );
		$this->assertSame( 'app', $rows[0]['source'] );
	}

	/** Non-POS payment completion is ignored. */
	public function test_payment_complete_on_non_pos_order_is_ignored(): void {
		// Arrange.
		$order = $this->create_order( false );
		$order->set_payment_method( 'pos_card' );
		$order->save();

		// Act.
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );

		// Assert.
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	/** A status edit during a POS request is not evidence of payment. */
	public function test_status_change_outside_order_pay_does_not_mint_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->in_pos_request(
			function () use ( $order ): void {
				// Act.
				$order->set_status( 'processing' );
				$order->save();

				// Assert.
				$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
			}
		);
	}

	/** An offline gateway landing a paid status from the order-pay form is captured. */
	public function test_order_pay_status_change_mints_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->in_pos_request(
			function () use ( $order ): void {
				// Act: WooCommerce fires this after the pay nonce and order key check,
				// immediately before it calls the gateway's process_payment().
				do_action( 'woocommerce_before_pay_action', $order );
				$order->set_status( 'processing' );
				$order->save();

				// Assert.
				$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
				$this->assertCount( 1, $rows );
				$this->assertSame( 'webview', $rows[0]['source'] );
			}
		);
	}

	/** An order-pay transition to an unpaid status does not mint a captured row. */
	public function test_order_pay_on_hold_status_does_not_mint_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->in_pos_request(
			function () use ( $order ): void {
				// Act.
				do_action( 'woocommerce_before_pay_action', $order );
				$order->set_status( 'on-hold' );
				$order->save();

				// Assert.
				$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
			}
		);
	}

	/** A paid status landed after the gateway answered is not the gateway paying. */
	public function test_status_change_after_gateway_result_does_not_mint_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->in_pos_request(
			function () use ( $order ): void {
				// Act: the pay form armed the order, the gateway returned success without
				// landing a status, and something moved the order to a paid status after.
				do_action( 'woocommerce_before_pay_action', $order );
				$result = apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() );
				$order->set_status( 'processing' );
				$order->save();

				// Assert.
				$this->assertSame( array( 'result' => 'success' ), $result, 'The result passes through untouched.' );
				$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
			}
		);
	}

	/**
	 * Run a callback with the POS request query var set.
	 *
	 * @param callable $callback Act and assert steps.
	 */
	private function in_pos_request( callable $callback ): void {
		$previous_query_vars                = $GLOBALS['wp']->query_vars;
		$GLOBALS['wp']->query_vars['wcpos'] = 1;

		try {
			$callback();
		} finally {
			$GLOBALS['wp']->query_vars = $previous_query_vars;
		}
	}

	/**
	 * Create an order at the passthrough contract total.
	 *
	 * @param bool $pos Whether the order is a POS order.
	 */
	private function create_order( bool $pos ): \WC_Order {
		$order = OrderHelper::create_order();
		if ( $pos ) {
			$order->set_created_via( 'woocommerce-pos' );
			$order->set_status( 'pos-open' );
		}
		$order->set_total( '92.95' );
		$order->save();

		return $order;
	}
}
