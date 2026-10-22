<?php
/**
 * Webview payment passthrough tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Save_Reentry_Guard;

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
					// Order-pay hands back the gateway's result: the mint deferred from the hook runs here.
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			);

			// Assert.
			$this->assertSame( $before, wc_get_order( $order->get_id() )->get_meta( Ledger::META_KEY, true ) );
			$errors = array_column( array_filter( $logs, static fn( $log ) => 'error' === $log[0] ), 1 );
			$this->assertCount( 1, $errors ); // mint() names the payment once; the hook adds nothing for a known refusal.
			$this->assertMatchesRegularExpression(
				sprintf( '/^WCPOS payment [0-9a-f-]{36} \\(92\\.95 [A-Z]{3}\\) captured but not recorded in the ledger on order #%d; reconcile manually: WCPOS payment ledger is locked\\. \\| Context: \\{"order_id":%d\\}$/', $order->get_id(), $order->get_id() ),
				$errors[0]
			);
		} finally {
			$lock->release( $order->get_id() );
			remove_filter( 'woocommerce_pos_logging', $log_filter, 10 );
			do_action( 'woocommerce_before_pay_action', null );
		}
	}

	/**
	 * A mint refused after WooCommerce took the money is loud: an error log and an order
	 * note name the payment, nothing is deferred, and the gateway hook is not interrupted.
	 *
	 * @dataProvider payment_hooks
	 *
	 * @param string $hook Payment hook.
	 */
	public function test_payment_hook_contended_mint_leaves_note_and_logs_without_throwing( string $hook ): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->set_transaction_id( 'hook_lost_charge' );
		$order->save();
		$before      = $order->get_meta( Ledger::META_KEY, true );
		$before_keys = array_column( array_map( static fn( $meta ) => $meta->get_data(), wc_get_order( $order->get_id() )->get_meta_data() ), 'key' );
		$errors      = array();
		$log_filter  = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		add_filter( 'woocommerce_pos_logging', $log_filter, 10, 2 );
		try {
			// Act: fire the hook directly so WooCommerce cannot swallow an escaped exception.
			$this->in_pos_request(
				static function () use ( $hook, $order ): void {
					do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
					do_action( $hook, $order->get_id(), 'pos-open', 'processing', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's hooks.
					// Order-pay hands back the gateway's result: the mint deferred from the hook runs here.
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			);
		} finally {
			$lock->release( $order->get_id() );
			remove_filter( 'woocommerce_pos_logging', $log_filter, 10 );
			do_action( 'woocommerce_before_pay_action', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		}

		// Assert.
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( $before, $saved->get_meta( Ledger::META_KEY, true ) );
		$this->assertSame( array(), Ledger::instance()->read( $saved ) );
		$this->assertCount( 1, array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'hook_lost_charge' ) ) );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'hook_lost_charge', $notes );
		$this->assertStringContainsString( '92.95', $notes );
		$after_keys = array_column( array_map( static fn( $meta ) => $meta->get_data(), $saved->get_meta_data() ), 'key' );
		$this->assertSame( array(), array_values( array_diff( $after_keys, $before_keys ) ) );
	}

	/**
	 * A ledger storage read error during a hook mint is loud and never escapes the hook.
	 *
	 * @dataProvider payment_hooks
	 *
	 * @param string $hook Payment hook.
	 */
	public function test_payment_hook_ledger_read_error_is_loud_without_throwing( string $hook ): void {
		global $wpdb;
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->set_transaction_id( 'read_error_charge' );
		$order->save();
		$errors = array();
		$log    = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		$break    = static fn( $sql ) => false !== strpos( $sql, 'AS meta_id, meta_value FROM' ) && false !== strpos( $sql, Ledger::META_KEY ) ? 'SELECT missing_column FROM wcpos_missing_table' : $sql;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'woocommerce_pos_logging', $log, 10, 2 );
		add_filter( 'query', $break );
		try {
			// Act.
			$this->in_pos_request(
				static function () use ( $hook, $order ): void {
					do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
					do_action( $hook, $order->get_id(), 'pos-open', 'processing', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's hooks.
					// Order-pay hands back the gateway's result: the mint deferred from the hook runs here.
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			);
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'woocommerce_pos_logging', $log, 10 );
			do_action( 'woocommerce_before_pay_action', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		}

		// Assert.
		$this->assertNotEmpty( array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'read_error_charge' ) && false !== strpos( $message, 'Ledger storage read failed.' ) ) );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'read_error_charge', $notes );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A failing note hook never replaces the original persistence failure, and the hook does not throw.
	 *
	 * @dataProvider payment_hooks
	 *
	 * @param string $hook Payment hook.
	 */
	public function test_payment_hook_failing_note_keeps_original_error_without_throwing( string $hook ): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->set_transaction_id( 'note_error_charge' );
		$order->save();
		$errors = array();
		$log    = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		$note = static function ( $comment_id, $comment ) {
			if ( 'order_note' === $comment->comment_type ) {
				throw new \RuntimeException( 'note hook failed' );
			}
		};
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		add_filter( 'woocommerce_pos_logging', $log, 10, 2 );
		add_action( 'wp_insert_comment', $note, 10, 2 );
		try {
			// Act.
			$this->in_pos_request(
				static function () use ( $hook, $order ): void {
					do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
					do_action( $hook, $order->get_id(), 'pos-open', 'processing', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's hooks.
					// Order-pay hands back the gateway's result: the mint deferred from the hook runs here.
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			);
		} finally {
			remove_action( 'wp_insert_comment', $note, 10 );
			$lock->release( $order->get_id() );
			remove_filter( 'woocommerce_pos_logging', $log, 10 );
			do_action( 'woocommerce_before_pay_action', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		}

		// Assert: the persistence failure is recorded once as itself, the note failure once as itself,
		// and the hook adds no line of its own for a known refusal.
		$this->assertCount( 1, array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'note_error_charge' ) && false !== strpos( $message, 'WCPOS payment ledger is locked.' ) ) );
		$this->assertCount( 1, array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'could not add the unrecorded-payment note' ) && false !== strpos( $message, 'note hook failed' ) ) );
		$this->assertSame( array(), array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'webview payment hook failed' ) ) );
	}

	/**
	 * An unexpected error before the ledger write is logged, and the gateway hook still does not throw.
	 *
	 * @dataProvider payment_hooks
	 *
	 * @param string $hook Payment hook.
	 */
	public function test_payment_hook_unexpected_error_is_logged_without_throwing( string $hook ): void {
		// Arrange: the order total read inside mint() fails before any ledger write.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->save();
		$errors = array();
		$log    = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		$crash = static function ( $total ) {
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( 'mint' === ( $frame['function'] ?? '' ) ) {
					throw new \Error( 'simulated total failure' );
				}
			}
			return $total;
		};
		add_filter( 'woocommerce_pos_logging', $log, 10, 2 );
		add_filter( 'woocommerce_order_get_total', $crash );
		try {
			// Act.
			$this->in_pos_request(
				static function () use ( $hook, $order ): void {
					do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
					do_action( $hook, $order->get_id(), 'pos-open', 'processing', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's hooks.
					// Order-pay hands back the gateway's result: the mint deferred from the hook runs here.
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			);
		} finally {
			remove_filter( 'woocommerce_order_get_total', $crash );
			remove_filter( 'woocommerce_pos_logging', $log, 10 );
			do_action( 'woocommerce_before_pay_action', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		}

		// Assert.
		$this->assertCount( 1, array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'webview payment hook failed' ) && false !== strpos( $message, 'simulated total failure' ) ) );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A failure while building the row after the money was taken is recorded loudly, and the hook does not throw.
	 *
	 * @dataProvider payment_hooks
	 *
	 * @param string $hook Payment hook.
	 */
	public function test_payment_hook_row_build_error_is_recorded_loudly_without_throwing( string $hook ): void {
		// Arrange: the second total read in mint() (the row amount) fails for the paying order.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->save();
		$errors = array();
		$log    = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		$reads = 0;
		$crash = static function ( $total, $paying ) use ( $order, &$reads ) {
			if ( $paying->get_id() !== $order->get_id() ) {
				return $total;
			}
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( 'mint' === ( $frame['function'] ?? '' ) && 2 === ++$reads ) {
					throw new \RuntimeException( 'total unavailable' );
				}
			}
			return $total;
		};
		add_filter( 'woocommerce_pos_logging', $log, 10, 2 );
		add_filter( 'woocommerce_order_get_total', $crash, 10, 2 );
		try {
			// Act.
			$this->in_pos_request(
				static function () use ( $hook, $order ): void {
					do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
					do_action( $hook, $order->get_id(), 'pos-open', 'processing', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's hooks.
					// Order-pay hands back the gateway's result: the mint deferred from the hook runs here.
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			);
		} finally {
			remove_filter( 'woocommerce_order_get_total', $crash, 10 );
			remove_filter( 'woocommerce_pos_logging', $log, 10 );
			do_action( 'woocommerce_before_pay_action', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		}

		// Assert.
		$this->assertCount( 1, array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'WCPOS payment pos_card' ) && false !== strpos( $message, 'captured but not recorded' ) && false !== strpos( $message, 'total unavailable' ) ) );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'WCPOS: payment pos_card', $notes );
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
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

	/** Payment completion mints a captured webview row once the hook has returned (a callback request: at shutdown). */
	public function test_payment_complete_on_pos_order_mints_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->set_transaction_id( 'ch_123' );
		$order->update_meta_data( '_pos_user', 123 );
		$order->save();

		// Act.
		Save_Reentry_Guard::strictly( fn() => $order->payment_complete() );
		$during = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		Ledger::flush_all();
		// The hook mints on its own wc_get_order() instance; re-read, as any later request would.
		$order = wc_get_order( $order->get_id() );
		$rows  = Ledger::instance()->read( $order );

		// Assert.
		$this->assertSame( array(), $during, 'Nothing is minted inside the payment hook.' );
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
				Save_Reentry_Guard::strictly(
					static function () use ( $order ): void {
						$order->set_status( 'processing' );
						$order->save();
					}
				);
				$during = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
				apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.

				// Assert: minted when order-pay hands back the gateway's result, not inside the status hook.
				$this->assertSame( array(), $during );
				$stored = wc_get_order( $order->get_id() );
				$rows   = Ledger::instance()->read( $stored );
				$this->assertCount( 1, $rows );
				$this->assertSame( 'webview', $rows[0]['source'] );
				$this->assertSame( array( $rows[0]['id'] ), Completion_History::of( $stored )->standing()['payment_ids'] );
			}
		);
	}

	/** An order-pay gateway's payment_complete() is minted and stamped when order-pay hands back its result. */
	public function test_order_pay_payment_complete_mints_and_stamps_after_the_hook_returns(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->set_transaction_id( 'ch_order_pay' );
		$order->save();
		$id     = $order->get_id();
		$during = null;
		$look   = static function () use ( $id, &$during ): void {
			$during = Ledger::instance()->read( wc_get_order( $id ) );
		};
		add_action( 'woocommerce_payment_complete', $look, 99 );

		// Act.
		do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		Save_Reentry_Guard::strictly( fn() => wc_get_order( $id )->payment_complete() );
		$result = apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
		remove_action( 'woocommerce_payment_complete', $look, 99 );

		// Assert.
		$this->assertSame( array(), $during, 'Nothing is minted inside the payment hook.' );
		$this->assertSame( array( 'result' => 'success' ), $result );
		$stored = wc_get_order( $id );
		$rows   = Ledger::instance()->read( $stored );
		$this->assertSame( array( 'ch_order_pay' ), array_column( array_column( $rows, 'provider_refs' ), 'transaction_id' ) );
		$this->assertSame( array( $rows[0]['id'] ), Completion_History::of( $stored )->standing()['payment_ids'] );
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

	/** A webview-mode gateway charges the order total, so order-pay refuses it on a partly paid sale (D3). */
	public function test_order_pay_on_a_partial_sale_is_refused(): void {
		// Arrange: 40.00 cash at the till on a 100.00 sale.
		$order = $this->create_partial_sale();
		$this->with_order_pay_gateway(
			'wcpos_test_full_total',
			'webview',
			function ( $gateway ) use ( $order ): void {
				$completed = did_action( 'woocommerce_payment_complete' );

				// Act.
				$this->pay_on_order_pay( $order, $gateway->id );

				// Assert.
				$fresh = wc_get_order( $order->get_id() );
				$this->assertSame( 0, $gateway->calls, 'The gateway is never asked to charge.' );
				$this->assertSame( $completed, did_action( 'woocommerce_payment_complete' ) );
				$this->assertCount( 1, Ledger::instance()->read( $fresh ) );
				$this->assertSame( 'pos-partial', $fresh->get_status() );
				$this->assertContains( 'This sale has a payment recorded at the till. Finish it at the till.', array_column( wc_get_notices( 'error' ), 'notice' ) );
				$this->assertArrayNotHasKey( $gateway->id, $this->on_pay_page( $order, static fn() => WC()->payment_gateways()->get_available_payment_gateways() ) );
				$this->assertSame( 'This sale has a payment recorded at the till. Finish it at the till.', $this->on_pay_page( $order, static fn() => apply_filters( 'woocommerce_no_available_payment_methods_message', 'none' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
			}
		);
	}

	/** The pay page's manual gateways compare against the order total too, so a partly paid sale refuses them. */
	public function test_order_pay_on_a_partial_sale_refuses_the_pos_cash_gateway(): void {
		// Arrange: pos_cash is offered on a POS sale's pay page, and the customer tenders the full total.
		$order = $this->create_partial_sale();
		$open = $this->create_order( true );
		$this->in_pos_request(
			function () use ( $open ): void {
				$this->assertArrayHasKey( 'pos_cash', $this->on_pay_page( $open, static fn() => WC()->payment_gateways()->get_available_payment_gateways() ), 'precondition: pos_cash is offered' );
			}
		);
		$_POST['pos_cash_payment_nonce_field'] = wp_create_nonce( 'pos_cash_payment_nonce' );
		$_POST['pos-cash-tendered']            = '100.00';
		$completed                             = did_action( 'woocommerce_payment_complete' );
		$redirect                              = static function () {
			throw new \RuntimeException( 'redirected' ); // WooCommerce's success branch exits after the redirect.
		};
		add_filter( 'wp_redirect', $redirect );

		// Act.
		try {
			$this->in_pos_request( fn() => $this->pay_on_order_pay( $order, 'pos_cash' ) );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'redirected', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
			unset( $_POST['pos_cash_payment_nonce_field'], $_POST['pos-cash-tendered'] );
			$errors = array_column( wc_get_notices( 'error' ), 'notice' );
			wc_clear_notices();
		}

		// Assert.
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( $completed, did_action( 'woocommerce_payment_complete' ), 'The sale is not marked paid.' );
		$this->assertCount( 1, Ledger::instance()->read( $fresh ) );
		$this->assertSame( 'pos-partial', $fresh->get_status() );
		$this->assertContains( 'This sale has a payment recorded at the till. Finish it at the till.', $errors );
	}

	/**
	 * A server or device gateway (Pro's terminal panel) charges the balance, so order-pay keeps it.
	 *
	 * @dataProvider balance_modes
	 *
	 * @param string $mode Capture mode the filter answers, with a handler registered for it.
	 */
	public function test_order_pay_on_a_partial_sale_with_a_server_mode_gateway_proceeds( string $mode ): void {
		// Arrange.
		$order = $this->create_partial_sale();
		$this->with_order_pay_gateway(
			'wcpos_test_terminal',
			$mode,
			function ( $gateway ) use ( $order ): void {
				// Act.
				$this->pay_on_order_pay( $order, $gateway->id );

				// Assert.
				$this->assertSame( 1, $gateway->calls );
				$this->assertArrayHasKey( $gateway->id, $this->on_pay_page( $order, static fn() => WC()->payment_gateways()->get_available_payment_gateways() ) );
			}
		);
	}

	/** Capture modes that charge the balance: bare, and provider-scoped. */
	public function balance_modes(): array {
		return array(
			'server'        => array( 'server' ),
			'device:stripe' => array( 'device:stripe' ),
		);
	}

	/** A filtered server mode with no registered handler is described as webview, so order-pay refuses it. */
	public function test_order_pay_on_a_partial_sale_refuses_a_server_mode_without_a_handler(): void {
		// Arrange.
		$order = $this->create_partial_sale();
		$this->with_order_pay_gateway(
			'wcpos_test_unhandled',
			'server',
			function ( $gateway ) use ( $order ): void {
				// Act.
				$this->pay_on_order_pay( $order, $gateway->id );

				// Assert.
				$this->assertSame( 0, $gateway->calls );
				$this->assertArrayNotHasKey( $gateway->id, $this->on_pay_page( $order, static fn() => WC()->payment_gateways()->get_available_payment_gateways() ) );
			},
			false
		);
	}

	/** A POS sale with no captured tender pays on order-pay with a webview-mode gateway as before. */
	public function test_order_pay_on_a_pos_sale_without_captured_tender_proceeds(): void {
		// Arrange.
		$order = $this->create_order( true );
		$this->with_order_pay_gateway(
			'wcpos_test_full_total',
			'webview',
			function ( $gateway ) use ( $order ): void {
				// Act: the test gateway answers failure (WooCommerce's success branch exits), so the mint waits for shutdown.
				$this->pay_on_order_pay( $order, $gateway->id );
				Ledger::flush_all();

				// Assert.
				$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
				$this->assertSame( 1, $gateway->calls );
				$this->assertCount( 1, $rows );
				$this->assertSame( 'webview', $rows[0]['source'] );
			}
		);
	}

	/** A 100.00 POS sale with 40.00 cash recorded at the till. */
	private function create_partial_sale(): \WC_Order {
		$order = $this->create_order( true );
		$order->set_total( '100.00' );
		$order->save();
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '40.00',
			)
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos-partial', $order->get_status(), 'precondition: the cash leaves the sale partly paid' );

		return $order;
	}

	/**
	 * Register a charging gateway whose capture mode resolves to the given mode for the callback.
	 *
	 * @param string   $id       Gateway id.
	 * @param string   $mode     Capture mode the wcpos_payment_method_capture_mode filter resolves.
	 * @param callable $callback Receives the gateway.
	 * @param bool     $handled  Register a handler for a mode Free does not ship (Pro registers server and device).
	 */
	private function with_order_pay_gateway( string $id, string $mode, callable $callback, bool $handled = true ): void {
		$gateway = new class( $id ) extends \WC_Payment_Gateway {
			/**
			 * Calls to process_payment().
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Assign the id without building form fields.
			 *
			 * @param string $id Gateway id.
			 */
			public function __construct( string $id ) {
				$this->id           = $id;
				$this->method_title = $id;
				$this->title        = $id;
				$this->enabled      = 'yes';
			}

			/**
			 * Charge the order total, as a WooCommerce gateway does.
			 *
			 * @param int $order_id Order id.
			 */
			public function process_payment( $order_id ) {
				++$this->calls;
				wc_get_order( $order_id )->payment_complete( 'ch_' . $this->id );
				return array( 'result' => 'failure' ); // WooCommerce's success branch exits.
			}
		};
		$add      = static fn( $gateways ) => array_merge( $gateways, array( $gateway ) );
		$resolve  = static fn( $resolved, $candidate ) => $candidate->id === $id ? $mode : $resolved;
		$registry = \WC_Payment_Gateways::instance();
		add_filter( 'woocommerce_payment_gateways', $add );
		add_filter( 'wcpos_payment_method_capture_mode', $resolve, 10, 2 );
		$registry->payment_gateways = array();
		$registry->init();
		if ( $handled && ! Capture_Mode_Registry::instance()->has( $mode ) ) {
			// A handler that describes nothing, standing in for Pro's: the descriptor keeps the filtered mode.
			$handler = new class() extends Abstract_Capture_Mode_Handler {
				/**
				 * Describe nothing.
				 *
				 * @param \WC_Payment_Gateway $gateway Gateway instance.
				 */
				public function describe( \WC_Payment_Gateway $gateway ): array {
					return array();
				}
			};
			Capture_Mode_Registry::instance()->register( $mode, get_class( $handler ) );
		}

		try {
			$callback( $gateway );
		} finally {
			remove_filter( 'woocommerce_payment_gateways', $add );
			remove_filter( 'wcpos_payment_method_capture_mode', $resolve, 10 );
			$registry->payment_gateways = array();
			$registry->init();
			wc_clear_notices();
			// Rebuild the process-global registry with Free's built-in modes only.
			$instance = new \ReflectionProperty( Capture_Mode_Registry::class, 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, null );
		}
	}

	/**
	 * POST WooCommerce's order-pay form for the order with the gateway, through WC_Form_Handler::pay_action().
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $gateway_id Chosen gateway.
	 */
	private function pay_on_order_pay( \WC_Order $order, string $gateway_id ): void {
		$previous_query_vars                     = $GLOBALS['wp']->query_vars;
		$GLOBALS['wp']->query_vars['order-pay'] = $order->get_id();
		$_GET['key']                             = $order->get_order_key();
		$_POST['woocommerce_pay']                = '1';
		$_POST['payment_method']                 = $gateway_id;
		$_REQUEST['woocommerce-pay-nonce']       = wp_create_nonce( 'woocommerce-pay' );
		$level                                   = ob_get_level();

		try {
			\WC_Form_Handler::pay_action();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$GLOBALS['wp']->query_vars = $previous_query_vars;
			unset( $_GET['key'], $_POST['woocommerce_pay'], $_POST['payment_method'], $_REQUEST['woocommerce-pay-nonce'] );
		}
	}

	/**
	 * Run a callback as the order's pay page.
	 *
	 * @param \WC_Order $order    Order.
	 * @param callable  $callback Returns what the page would read.
	 * @return mixed
	 */
	private function on_pay_page( \WC_Order $order, callable $callback ) {
		$previous_query_vars                     = $GLOBALS['wp']->query_vars;
		$GLOBALS['wp']->query_vars['order-pay'] = $order->get_id();
		try {
			return $callback();
		} finally {
			$GLOBALS['wp']->query_vars = $previous_query_vars;
		}
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
