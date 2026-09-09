<?php
/**
 * Stale payment reconciliation tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Payments\Contract\Payments_Sweeper;

class Test_Payments_Sweeper extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		Capture_Mode_Registry::instance()->register( 'sweep_test', Sweep_Test_Handler::class );
		Sweep_Test_Handler::$calls = array();
		Sweep_Test_Handler::$voids = array();
		Sweep_Test_Handler::$preserve_authorized = false;
		Sweep_Test_Handler::$patch = array( 'status' => 'captured' );
		Sweep_Test_Handler::$captures = array();
		Sweep_Test_Handler::$capture_patch = array( 'status' => 'captured' );
	}

	public function test_sweeper_stale_pending_is_polled_but_fresh_row_is_not(): void {
		list( $stale, $id ) = $this->leg( 600 );
		list( $fresh, $fresh_id ) = $this->leg( 0 );
		( new Payments_Sweeper() )->run();
		$this->assertSame( array( $id ), Sweep_Test_Handler::$calls );
		$this->assertSame( 'captured', $this->row( $stale, $id )['status'] );
		$this->assertSame( 'pending', $this->row( $fresh, $fresh_id )['status'] );
		$this->assertSame( 'pos-partial', wc_get_order( $stale->get_id() )->get_status() );
	}

	public function test_sweeper_expired_live_row_is_voided_after_status(): void {
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		list( $order, $id ) = $this->leg( 600, array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		( new Payments_Sweeper() )->run();
		$this->assertSame( array( $id ), Sweep_Test_Handler::$calls );
		$this->assertSame( array( array( $id, 'expired', 'authorized' ) ), Sweep_Test_Handler::$voids );
		$this->assertSame( 'voided', $this->row( $order, $id )['status'] );
	}

	public function test_sweeper_captured_or_extended_leg_is_not_voided(): void {
		list( $order, $id ) = $this->leg( 600, array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		( new Payments_Sweeper() )->run();
		$this->assertSame( 'captured', $this->row( $order, $id )['status'] );
		Sweep_Test_Handler::$patch = array( 'status' => 'pending', 'expires_at' => gmdate( 'c', time() + 600 ) );
		$this->leg( 600, array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		( new Payments_Sweeper() )->run();
		$this->assertSame( array(), Sweep_Test_Handler::$voids );
	}

	public function test_sweeper_missing_handler_and_busy_order_are_skipped(): void {
		list( $missing, $missing_id ) = $this->leg( 600, array( 'capture_mode' => 'unregistered_sweeper_test' ) );
		list( $busy, $busy_id ) = $this->leg( 600 );
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $busy->get_id() ) );
		try {
			( new Payments_Sweeper() )->run();
			$this->assertSame( array(), Sweep_Test_Handler::$calls );
			$this->assertSame( 'pending', $this->row( $missing, $missing_id )['status'] );
			$this->assertSame( 'pending', $this->row( $busy, $busy_id )['status'] );
		} finally {
			$lock->release( $busy->get_id() );
		}
	}

	public function test_sweeper_does_not_request_a_cancel_twice(): void {
		// A handler that treats cancel as a request stamps void_requested_at and keeps the
		// row pending until the provider confirms; the sweep must not ask again.
		Sweep_Test_Handler::$patch = array( 'status' => 'pending' );
		list( $order, $id ) = $this->leg( 600, array( 'expires_at' => gmdate( 'c', time() - 60 ), 'void_requested_at' => gmdate( 'c', time() - 30 ) ) );
		( new Payments_Sweeper() )->run();
		$this->assertSame( array( $id ), Sweep_Test_Handler::$calls );
		$this->assertSame( array(), Sweep_Test_Handler::$voids );
		$this->assertSame( 'pending', $this->row( $order, $id )['status'] );
	}

	public function test_sweeper_status_error_leaves_expired_row_untouched(): void {
		Sweep_Test_Handler::$patch = new \WP_Error( 'test_provider_unavailable', 'Unavailable' );
		list( $order, $id ) = $this->leg( 600, array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		$before = $this->row( $order, $id );
		( new Payments_Sweeper() )->run();
		$this->assertSame( $before, $this->row( $order, $id ) );
		$this->assertSame( array(), Sweep_Test_Handler::$voids );
	}

	public function test_sweeper_amount_mismatch_uses_capture_verification(): void {
		Sweep_Test_Handler::$patch = array( 'status' => 'captured', 'amount' => '999.00' );
		list( $order, $id ) = $this->leg( 600 );
		( new Payments_Sweeper() )->run();
		$this->assertSame( 'failed', $this->row( $order, $id )['status'] );
		$this->assertSame( 'amount_mismatch', $this->row( $order, $id )['failure_reason'] );
	}

	public function test_sweeper_batch_cap_selects_oldest_modified_first(): void {
		add_filter( 'wcpos_payments_sweep_batch_size', static function () { return 1; } );
		list( $newer, $newer_id ) = $this->leg( 600 );
		list( $older, $older_id ) = $this->leg( 1200 );
		// Written straight to the table: on the CPT datastore wp_insert_post() stamps
		// post_modified to now on every update, so set_date_modified() would not stick.
		$this->backdate_modified( $newer, time() - 600 );
		$this->backdate_modified( $older, time() - 1200 );
		( new Payments_Sweeper() )->run();
		$this->assertSame( array( $older_id ), Sweep_Test_Handler::$calls );
		$this->assertSame( 'pending', $this->row( $newer, $newer_id )['status'] );
	}

	public function test_sweeper_threshold_and_created_timestamp_fallback(): void {
		add_filter( 'wcpos_payments_sweep_threshold', static function () { return 1000; } );
		$this->leg( 600 );
		list( $order, $id ) = $this->leg( 1200 );
		$rows = Ledger::instance()->read( $order );
		unset( $rows[0]['updated_at_gmt'] );
		$order->update_meta_data( Ledger::META_KEY, wp_json_encode( array( 'schema' => 1, 'payments' => $rows ) ) );
		$order->save();
		( new Payments_Sweeper() )->run();
		$this->assertSame( array( $id ), Sweep_Test_Handler::$calls );
	}

	public function test_sweeper_reaches_a_paid_order_that_still_holds_an_authorization(): void {
		// A captured leg on a completed order has nothing to reconcile: no live index, not polled.
		list( $settled ) = $this->leg( 600, array( 'status' => 'captured' ) );
		$settled->set_status( 'completed' );
		$settled->save();
		// An authorization that completed the order and then lapsed is exactly what the
		// sweep exists for — a status filter would never see it (Codex review, #1904).
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized', 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		$order->set_status( 'completed' );
		$order->save();
		( new Payments_Sweeper() )->run();
		$this->assertSame( array( $id ), Sweep_Test_Handler::$calls );
		$this->assertSame( 'voided', $this->row( $order, $id )['status'] );
		// Never unwound (§3.3); flagged where staff look instead.
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $note ) => false !== strpos( $note, 'expired after the order was marked paid' ) ) );
		$this->assertEmpty( wc_get_order( $order->get_id() )->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );
	}

	public function test_sweeper_registers_single_ten_minute_schedule(): void {
		wp_clear_scheduled_hook( Payments_Sweeper::HOOK );
		$sweeper = new Payments_Sweeper();
		$sweeper->register_hooks();
		$first = wp_next_scheduled( Payments_Sweeper::HOOK );
		$sweeper->register_hooks();
		$this->assertSame( $first, wp_next_scheduled( Payments_Sweeper::HOOK ) );
		$this->assertSame( 'wcpos_ten_minutes', wp_get_schedule( Payments_Sweeper::HOOK ) );
		$this->assertSame( 600, wp_get_schedules()['wcpos_ten_minutes']['interval'] );
		wp_clear_scheduled_hook( Payments_Sweeper::HOOK );
	}

	public function test_sweeper_full_balance_expired_authorization_never_completes_order(): void {
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		list( $order, $id ) = $this->leg( 600, array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		$order->set_total( '20.00' );
		$order->save();
		( new Payments_Sweeper() )->run();
		$this->assertSame( 'voided', $this->row( $order, $id )['status'] );
		$this->assertNull( wc_get_order( $order->get_id() )->get_date_paid() );
		$this->assertSame( 'pos-open', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_sweeper_split_capture_does_not_count_an_authorization_about_to_expire(): void {
		list( $order, $id ) = $this->leg( 600 );
		$rows = Ledger::instance()->read( $order );
		$expired = array_merge( $rows[0], array( 'id' => wp_generate_uuid4(), 'amount' => '80.00', 'status' => 'authorized', 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		$order->set_total( '100.00' );
		Ledger::instance()->save( $order, array( $rows[0], $expired ) );
		$rows = Ledger::instance()->read( $order );
		foreach ( $rows as &$row ) { $row['updated_at_gmt'] = gmdate( 'c', time() - 600 ); }
		unset( $row );
		$order->update_meta_data( Ledger::META_KEY, wp_json_encode( array( 'schema' => 1, 'payments' => $rows ) ) );
		$order->save();
		Sweep_Test_Handler::$patch = array( 'status' => 'captured' );
		Sweep_Test_Handler::$preserve_authorized = true;
		( new Payments_Sweeper() )->run();
		$this->assertSame( 'captured', $this->row( $order, $id )['status'] );
		$this->assertSame( 'voided', $this->row( $order, $expired['id'] )['status'] );
		$this->assertNull( wc_get_order( $order->get_id() )->get_date_paid() );
		$this->assertSame( 'pos-partial', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_sweeper_captures_a_standing_authorization_on_a_completed_order(): void {
		// A full-balance authorization completes the order (§3.3), the order leaves the
		// open-orders set the app resumes from, and a manual-capture provider is never
		// asked to capture. The sweep owes that capture (wcpos/roadmap#169).
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized', 'expires_at' => gmdate( 'c', time() + 3600 ) ) );
		$order->set_status( 'completed' );
		$order->save();
		( new Payments_Sweeper() )->run();
		$this->assertSame( array( array( $id, 'authorized', 'sweep' ) ), Sweep_Test_Handler::$captures );
		$this->assertSame( array(), Sweep_Test_Handler::$voids );
		$this->assertSame( 'captured', $this->row( $order, $id )['status'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertEmpty( wc_get_order( $order->get_id() )->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );
	}

	public function test_sweeper_leaves_an_authorization_on_an_in_progress_order_to_its_till(): void {
		// A partial authorization keeps the order open; the till that owns the leg resumes
		// it on reload and may still cancel it, so the sweep does not pre-empt that.
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized', 'expires_at' => gmdate( 'c', time() + 3600 ) ) );
		( new Payments_Sweeper() )->run();
		$this->assertSame( array(), Sweep_Test_Handler::$captures );
		$this->assertSame( 'authorized', $this->row( $order, $id )['status'] );
		$this->assertSame( 'pos-partial', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_sweeper_does_not_capture_an_authorization_being_cancelled(): void {
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized', 'void_requested_at' => gmdate( 'c', time() - 30 ) ) );
		$order->set_status( 'completed' );
		$order->save();
		( new Payments_Sweeper() )->run();
		$this->assertSame( array(), Sweep_Test_Handler::$captures );
		$this->assertSame( 'authorized', $this->row( $order, $id )['status'] );
	}

	public function test_sweeper_never_captures_on_a_cancelled_or_refunded_order(): void {
		// Cancelled and refunded are outside the in-progress set too, but they are not paid:
		// charging a customer for an order staff cancelled is the one thing the sweep must
		// never do (Greptile/Codex on #1926). The hold lapses at expires_at instead.
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		foreach ( array( 'cancelled', 'refunded', 'on-hold' ) as $status ) {
			list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized', 'expires_at' => gmdate( 'c', time() + 3600 ) ) );
			$order->set_status( $status );
			$order->save();
			( new Payments_Sweeper() )->run();
			$this->assertSame( array(), Sweep_Test_Handler::$captures, $status );
			$this->assertSame( 'authorized', $this->row( $order, $id )['status'], $status );
		}
	}

	public function test_sweeper_leaves_an_authorize_only_provider_alone(): void {
		// A handler without capture() answers unsupported: authorized is that provider's
		// settled state, so the sweep asks once per run and records nothing.
		$logs = array();
		$capture_log = static function ( $enabled, $message ) use ( &$logs ) {
			$logs[] = $message;
			return false;
		};
		add_filter( 'woocommerce_pos_logging', $capture_log, 10, 2 );
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		Sweep_Test_Handler::$capture_patch = new \WP_Error( 'wcpos_capture_mode_unsupported', 'Unsupported', array( 'status' => 501 ) );
		list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized' ) );
		$order->set_status( 'completed' );
		$order->save();
		try {
			( new Payments_Sweeper() )->run();
		} finally {
			remove_filter( 'woocommerce_pos_logging', $capture_log, 10 );
		}
		$this->assertCount( 1, Sweep_Test_Handler::$captures );
		$this->assertSame( 'authorized', $this->row( $order, $id )['status'] );
		$this->assertSame( array(), Sweep_Test_Handler::$voids );
		// Only our line matters: the Logger's dedup flush can emit an earlier test's
		// "repeated N times" through the same filter, so a bare count is order-fragile.
		$this->assertSame( array(), $this->refusal_logs( $logs ) );
	}

	public function test_sweeper_refused_capture_leaves_the_authorization_standing(): void {
		$logs = array();
		$capture_log = static function ( $enabled, $message ) use ( &$logs ) {
			$logs[] = $message;
			return false;
		};
		add_filter( 'woocommerce_pos_logging', $capture_log, 10, 2 );
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		Sweep_Test_Handler::$capture_patch = new \WP_Error( 'wcpos_provider_error', 'Declined by the provider' );
		list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized', 'expires_at' => gmdate( 'c', time() + 3600 ) ) );
		$order->set_status( 'completed' );
		$order->save();
		try {
			( new Payments_Sweeper() )->run();
		} finally {
			remove_filter( 'woocommerce_pos_logging', $capture_log, 10 );
		}
		$this->assertCount( 1, Sweep_Test_Handler::$captures );
		$this->assertSame( 'authorized', $this->row( $order, $id )['status'] );
		$this->assertSame( array(), Sweep_Test_Handler::$voids );
		$this->assertCount( 1, $this->refusal_logs( $logs ) );
		$this->assertStringContainsString( 'capture refused: Declined by the provider', $this->refusal_logs( $logs )[0] );
		// Still live: the next run asks again, and expiry still voids it.
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );
	}

	public function test_sweeper_capture_uses_capture_verification(): void {
		Sweep_Test_Handler::$patch = array( 'status' => 'authorized' );
		Sweep_Test_Handler::$capture_patch = array( 'status' => 'captured', 'amount' => '999.00' );
		list( $order, $id ) = $this->leg( 600, array( 'status' => 'authorized' ) );
		$order->set_status( 'completed' );
		$order->save();
		( new Payments_Sweeper() )->run();
		$this->assertSame( 'failed', $this->row( $order, $id )['status'] );
		$this->assertSame( 'amount_mismatch', $this->row( $order, $id )['failure_reason'] );
	}

	/** The sweep's capture-refusal lines out of everything the logging filter saw. */
	private function refusal_logs( array $logs ): array {
		return array_values( array_filter( $logs, static fn( $line ) => false !== strpos( (string) $line, 'capture refused' ) ) );
	}

	/** Backdate an order's modified stamp in whichever order table is active. */
	private function backdate_modified( \WC_Order $order, int $timestamp ): void {
		global $wpdb;
		$stamp = gmdate( 'Y-m-d H:i:s', $timestamp );
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $stamp ), array( 'id' => $order->get_id() ) );
		} else {
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $stamp, 'post_modified' => $stamp ), array( 'ID' => $order->get_id() ) );
			clean_post_cache( $order->get_id() );
		}
	}

	private function row( \WC_Order $order, string $id ): array {
		return Ledger::instance()->find( wc_get_order( $order->get_id() ), $id );
	}

	private function leg( int $age, array $extra = array() ): array {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$row = array_merge( array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_card', 'capture_mode' => 'sweep_test', 'amount' => '20.00', 'status' => 'pending' ), $extra );
		Ledger::instance()->save( $order, array( $row ) );
		$rows = Ledger::instance()->read( $order );
		$rows[0]['updated_at_gmt'] = gmdate( 'c', time() - $age );
		$rows[0]['created_at_gmt'] = gmdate( 'c', time() - $age );
		$order->update_meta_data( Ledger::META_KEY, wp_json_encode( array( 'schema' => 1, 'payments' => $rows ) ) );
		$order->save();
		return array( $order, $row['id'] );
	}
}

class Sweep_Test_Handler extends Manual_Handler {
	public static $calls = array();
	public static $voids = array();
	public static $patch = array();
	public static $preserve_authorized = false;
	public static $captures = array();
	public static $capture_patch = array();
	public function status( array $row ) {
		self::$calls[] = $row['id'];
		return self::$preserve_authorized && 'authorized' === $row['status'] ? $row : self::$patch;
	}
	public function void( array $row, string $reason ) {
		self::$voids[] = array( $row['id'], $reason, $row['status'] );
		return array( 'status' => 'voided' );
	}
	public function capture( array $row, array $context ) {
		self::$captures[] = array( $row['id'], $row['status'], $context['source'] ?? null );
		return self::$capture_patch;
	}
}
