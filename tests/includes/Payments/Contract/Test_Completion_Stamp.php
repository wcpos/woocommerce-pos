<?php
/**
 * Completion history tests (ADR 0040 rule 1c, spec §4.4).
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Webview_Passthrough;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;

/** One entry per completion at a register, from every completion path, with its own provenance. */
class Test_Completion_Stamp extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

	private const REGISTER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
	private const REGISTER_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
	private const SESSION_A  = 'aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa';
	private const SESSION_B  = 'bbbbbbbb-2222-4bbb-8bbb-bbbbbbbbbbbb';

	/**
	 * Completion numbers announced by `wcpos_sale_completed`, in order.
	 *
	 * @var int[]
	 */
	protected $announced = array();

	/**
	 * Whether the gateway fixtures are POS-enabled.
	 *
	 * @var bool
	 */
	private static $fixtures_enabled = true;

	/** Register the gateway fixtures and the completion listener. */
	public function setUp(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ) );
		add_filter( 'wcpos_payment_method_capture_mode', array( $this, 'mode' ), 10, 2 );
		add_filter( 'wcpos_payment_method_fields', array( $this, 'fields' ), 10, 2 );
		$this->configure();
		parent::setUp();
		\WC_Payment_Gateways::instance()->init();
		Stamp_Sent_Gateway::$land_status = 'pending';
		Stamp_Paid_Gateway::$status_only = false;
		$this->announced = array();
		add_action( 'wcpos_sale_completed', array( $this, 'announce' ), 10, 2 );
	}

	/** Remove the listener. */
	public function tearDown(): void {
		remove_action( 'wcpos_sale_completed', array( $this, 'announce' ), 10 );
		remove_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'keep_fixtures_enabled' ), 20 );
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ) );
		remove_filter( 'wcpos_payment_method_capture_mode', array( $this, 'mode' ), 10 );
		remove_filter( 'wcpos_payment_method_fields', array( $this, 'fields' ), 10 );
		parent::tearDown();
	}

	/**
	 * Record each announced completion.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $n     Completion number.
	 */
	public function announce( $order, $n ): void {
		$this->announced[] = $n;
	}

	/** A ledger completion writes exactly one entry, naming the command's register. */
	public function test_a_ledger_completion_writes_exactly_one_entry_with_the_commands_provenance(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$response = $this->record( $order, '92.95', $this->provenance( self::REGISTER_A, self::SESSION_A ) );

		// Assert: read storage, not the route's object.
		$this->assertSame( 200, $response->get_status() );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( 1, $history[0]['n'] );
		$this->assertSame( self::REGISTER_A, $history[0]['register'] );
		$this->assertSame( self::SESSION_A, $history[0]['session'] );
		$this->assertSame( '2026-10-20T09:15:00+02:00', $history[0]['sale_time'] );
		$this->assertSame( 'Europe/Berlin', $history[0]['sale_tz'] );
		$this->assertSame( '42', $history[0]['counter'] );
		$this->assertSame( '92.95', $history[0]['total'] );
		$this->assertSame( array( $response->get_data()['payment']['id'] ), $history[0]['payment_ids'] );
		$this->assertNull( $history[0]['reopened_at_gmt'] );
		$this->assertTrue( wp_is_uuid( $history[0]['id'] ) );
		$this->assertSame( $history[0]['at_gmt'], $this->stored_meta( $order->get_id(), Completion_Stamp::COMPLETED_AT_META_KEY ) );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** Without a provenance body, completion 1 takes the write-once till tuple. */
	public function test_completion_without_a_provenance_body_falls_back_to_the_till_tuple(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->update_meta_data( '_wcpos_register', self::REGISTER_B );
		$order->update_meta_data( '_wcpos_session', self::SESSION_B );
		$order->update_meta_data( '_wcpos_sale_time', '2026-10-19T08:00:00Z' );
		$order->update_meta_data( '_wcpos_sale_tz', 'UTC' );
		$order->update_meta_data( '_wcpos_sale_counter', '7' );
		$order->save();

		// Act.
		$this->record( $order, '92.95' );

		// Assert.
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( self::REGISTER_B, $history[0]['register'] );
		$this->assertSame( self::SESSION_B, $history[0]['session'] );
		$this->assertSame( '2026-10-19T08:00:00Z', $history[0]['sale_time'] );
		$this->assertSame( 'UTC', $history[0]['sale_tz'] );
		$this->assertSame( '7', $history[0]['counter'] );
	}

	/** An invalid provenance field is dropped, never refused. */
	public function test_an_invalid_provenance_field_is_dropped_and_the_command_still_runs(): void {
		// Arrange.
		$order      = $this->create_pos_order();
		$provenance = $this->provenance( 'not-a-uuid', self::SESSION_A );

		// Act.
		$response = $this->record( $order, '92.95', $provenance );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$history = $this->stored_history( $order->get_id() );
		$this->assertNull( $history[0]['register'] );
		$this->assertSame( self::SESSION_A, $history[0]['session'] );
	}

	/** A reopen marks the latest entry and writes no completion. */
	public function test_a_reopen_marks_the_latest_entry_and_writes_no_entry(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->record( $order, '92.95', $this->provenance( self::REGISTER_A, self::SESSION_A ) );

		// Act.
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();

		// Assert.
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertNotNull( $history[0]['reopened_at_gmt'] );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** An order completed before the history existed: the reopen synthesises entry 1, close writes entry 2. */
	public function test_a_pre_deploy_completed_order_reopened_and_closed_writes_completion_2(): void {
		global $wpdb;
		// Arrange: a paid cash sale with a fiscal sale row and no history, as the previous version left it.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order = $this->create_pos_order();
		$cash  = array(
			'id' => wp_generate_uuid4(),
			'method_id' => 'pos_cash',
			'kind' => 'cash',
			'capture_mode' => 'manual',
			'amount' => '92.95',
			'status' => 'captured',
		);
		Ledger_Seed::rows( $order, array( $cash ), false );
		remove_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5 );
		try {
			$paid = wc_get_order( $order->get_id() );
			$paid->set_date_paid( time() );
			$paid->set_status( 'completed' );
			$paid->save();
		} finally {
			add_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5, 4 );
		}
		$store = new Fiscal_Record_Store();
		$store->ensure_installed();
		$wpdb->insert(
			$store->table_name(),
			array(
				'type' => 'sale',
				'series' => '',
				'number' => 900,
				'order_id' => $order->get_id(),
				'register_id' => self::REGISTER_A,
				'payload' => wp_json_encode( array( 'totals' => array( 'total_incl' => 92.95 ) ) ),
				'checksum' => str_repeat( 'a', 64 ),
				'received_at_gmt' => '2026-10-01 10:00:00',
			)
		);
		$legacy_row = (int) $wpdb->insert_id;
		$this->assertSame( array(), $this->stored_history( $order->get_id() ) );

		// Act: reopen, then close from register B.
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$synthesised = $this->stored_history( $order->get_id() );
		$request     = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/close' );
		$request->set_body_params( $this->envelope( $order, array( 'provenance' => $this->provenance( self::REGISTER_B, self::SESSION_B ) ) ) );
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertCount( 1, $synthesised );
		$this->assertTrue( $synthesised[0]['legacy'] );
		$this->assertSame( Fiscal_Record_Store::digest_source_id( 'sale:' . $legacy_row ), $synthesised[0]['id'] );
		$this->assertSame( array( $cash['id'] ), $synthesised[0]['payment_ids'] );
		$this->assertSame( self::REGISTER_A, $synthesised[0]['register'] );
		$this->assertNotNull( $synthesised[0]['reopened_at_gmt'] );
		$this->assertSame( 200, $response->get_status() );
		$history = $this->stored_history( $order->get_id() );
		$this->assertSame( array( 1, 2 ), array_column( $history, 'n' ) );
		$this->assertSame( self::REGISTER_B, $history[1]['register'] );
		$this->assertSame( array( 2 ), $this->announced );
		$two = $store->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertSame( $legacy_row, $two['corrects_record_id'] );
		$this->assertSame( $synthesised[0]['id'], $two['payload']['fiscal']['supersedes']['completion_id'] );
		$this->assertSame( '0.00', $two['payload']['fiscal']['adjustment'] );
	}

	/** A webview (order-pay) completion writes one entry. */
	public function test_webview_order_pay_completion_writes_one_entry(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_payment_method( 'pos_card' );
		$order->save();

		// Act: WooCommerce's order-pay form runs the gateway, which completes the payment.
		$this->in_pos_request(
			static function () use ( $order ): void {
				do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
				try {
					wc_get_order( $order->get_id() )->payment_complete( 'ch_webview' );
				} finally {
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			}
		);

		// Assert.
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertCount( 1, $this->stored_history( $order->get_id() ) );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** A gateway callback completing a sent submit in a later request names the submit's register. */
	public function test_gateway_callback_completion_names_the_submits_register(): void {
		// Arrange: submit from register B answers sent.
		$order    = $this->create_pos_order();
		$response = $this->submit( $order, wp_generate_uuid4(), 'wcpos_stamp_sent', $this->provenance( self::REGISTER_B, self::SESSION_B ) );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );

		// Act: the provider's callback completes the payment.
		wc_get_order( $order->get_id() )->payment_complete( 'txn_callback' );
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.

		// Assert.
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( self::REGISTER_B, $history[0]['register'] );
		$this->assertSame( self::SESSION_B, $history[0]['session'] );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** A sent attempt completed by a later callback is stamped after the passthrough mints its row. */
	public function test_a_sent_attempt_callback_stamps_after_the_mint_with_its_row(): void {
		// Arrange: submit from register B answers sent; the callback is a later request.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order    = $this->create_pos_order();
		$response = $this->submit( $order, wp_generate_uuid4(), 'wcpos_stamp_sent', $this->provenance( self::REGISTER_B, self::SESSION_B ) );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );

		// Act.
		wc_get_order( $order->get_id() )->payment_complete( 'txn_callback' );
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.

		// Assert.
		$rows    = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertCount( 1, $history );
		$this->assertSame( self::REGISTER_B, $history[0]['register'] );
		$this->assertSame( array( $rows[0]['id'] ), $history[0]['payment_ids'] );
		$this->assertSame( 'webview', $rows[0]['source'] ); // The passthrough minted it; the stamp minted nothing.
		$this->assertSame( array( 1 ), $this->announced );
		$payments = $this->only_sale( $order->get_id() )['payload']['payments'];
		$this->assertCount( 1, $payments );
		$this->assertSame( 'txn_callback', $payments[0]['transaction_id'] );
	}

	/** A sent attempt the merchant marks paid without payment_complete() mints its own row, then is stamped. */
	public function test_a_sent_attempt_completed_by_a_paid_status_write_mints_its_row(): void {
		// Arrange: submit from register B answers sent; the status write is a later request.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order    = $this->create_pos_order();
		$attempt  = wp_generate_uuid4();
		$response = $this->submit( $order, $attempt, 'wcpos_stamp_sent', $this->provenance( self::REGISTER_B, self::SESSION_B ) );
		$this->assertSame( 'sent', $response->get_data()['outcome'] );

		// Act: wp-admin marks the bank transfer paid.
		wc_get_order( $order->get_id() )->update_status( 'processing' );
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.

		// Assert.
		$stored  = wc_get_order( $order->get_id() );
		$rows    = Ledger::instance()->read( $stored );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertSame( $attempt, $rows[0]['id'] );
		$this->assertSame( 'captured', $rows[0]['status'] );
		$this->assertSame( 'app', $rows[0]['source'] );
		$this->assertSame( 'gateway', $rows[0]['capture_mode'] );
		$this->assertSame( 'wcpos_stamp_sent', $rows[0]['method_id'] );
		$this->assertSame( array( $attempt => 'paid' ), \WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission::read_attempts( $stored ) );
		$this->assertCount( 1, $history );
		$this->assertSame( self::REGISTER_B, $history[0]['register'] );
		$this->assertSame( array( $attempt ), $history[0]['payment_ids'] );
		$this->assertCount( 1, $this->only_sale( $order->get_id() )['payload']['payments'] );

		// Fulfilment afterwards writes nothing more.
		wc_get_order( $order->get_id() )->update_status( 'completed' );
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertCount( 1, $this->stored_history( $order->get_id() ) );
		$this->assertCount( 1, ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id(), 'type' => 'sale' ) ) );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** A payment_complete() that takes WooCommerce's no-op branch leaves no flag behind on its order. */
	public function test_a_no_op_payment_complete_does_not_stop_a_later_paid_status_write_minting(): void {
		// Arrange: a sent attempt; elsewhere, a payment_complete() on an order already completed.
		$order    = $this->create_pos_order();
		$attempt  = wp_generate_uuid4();
		$this->assertSame( 'sent', $this->submit( $order, $attempt, 'wcpos_stamp_sent', $this->provenance( self::REGISTER_B, self::SESSION_B ) )->get_data()['outcome'] );
		$done = OrderHelper::create_order();
		$done->set_status( 'completed' );
		$done->save();
		wc_get_order( $done->get_id() )->payment_complete();
		// The same order takes the no-op branch too: payment_complete() refused on its status.
		$refuse = static fn( $statuses ) => array_diff( $statuses, array( 'pending' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $refuse, PHP_INT_MAX );
		try {
			wc_get_order( $order->get_id() )->payment_complete();
		} finally {
			remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $refuse, PHP_INT_MAX );
		}
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );

		// Act: later in the request, the merchant marks the invoice paid.
		wc_get_order( $order->get_id() )->update_status( 'processing' );
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.

		// Assert.
		$this->assertSame( array( $attempt ), array_column( Ledger::instance()->read( wc_get_order( $order->get_id() ) ), 'id' ) );
		$this->assertSame( array( array( $attempt ) ), array_column( $this->stored_history( $order->get_id() ), 'payment_ids' ) );
	}

	/** A gateway that lands a paid status without payment_complete() writes one entry. */
	public function test_a_gateway_that_only_lands_a_paid_status_writes_one_entry(): void {
		// Arrange: the stored POS status is WooCommerce's default (completed); the gateway lands processing.
		Stamp_Paid_Gateway::$status_only = true;
		$order                           = $this->create_pos_order();

		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), 'wcpos_stamp_paid', $this->provenance( self::REGISTER_A, self::SESSION_A ) );

		// Assert.
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( self::REGISTER_A, $history[0]['register'] );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/**
	 * The sale record written for the order's single completion.
	 *
	 * @param int $order_id Order ID.
	 */
	protected function only_sale( int $order_id ): array {
		$history = $this->stored_history( $order_id );
		$this->assertCount( 1, $history );
		$sale = ( new Fiscal_Record_Store() )->find_sale( $order_id, $history[0]['id'] );
		$this->assertIsArray( $sale );
		return $sale;
	}

	/** A submit that pays inside its window is stamped after the mint, naming the paying row. */
	public function test_a_submit_that_pays_in_the_window_stamps_after_the_mint_with_its_row(): void {
		// Arrange.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order   = $this->create_pos_order();
		$attempt = wp_generate_uuid4();

		// Act.
		$response = $this->submit( $order, $attempt, 'wcpos_stamp_paid', $this->provenance( self::REGISTER_A, self::SESSION_A ) );

		// Assert.
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( array( $attempt ), $history[0]['payment_ids'] );
		$this->assertSame( array( 1 ), $this->announced );
		$payments = $this->only_sale( $order->get_id() )['payload']['payments'];
		$this->assertCount( 1, $payments );
		$this->assertSame( 'wcpos_stamp_paid', $payments[0]['method_id'] );
	}

	/** An order-pay gateway that calls payment_complete() in the window is stamped after the webview mint. */
	public function test_an_order_pay_payment_complete_in_the_window_stamps_after_the_mint(): void {
		// Arrange.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order = $this->create_pos_order();
		$order->set_payment_method( 'pos_card' );
		$order->save();

		// Act.
		$this->in_pos_request(
			static function () use ( $order ): void {
				do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
				try {
					wc_get_order( $order->get_id() )->payment_complete( 'ch_window' );
				} finally {
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			}
		);

		// Assert.
		$rows    = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertCount( 1, $history );
		$this->assertSame( array( $rows[0]['id'] ), $history[0]['payment_ids'] );
		$this->assertSame( array( 1 ), $this->announced );
		$this->assertCount( 1, $this->only_sale( $order->get_id() )['payload']['payments'] );
	}

	/** An order-pay gateway that only lands a paid status in the window is stamped after the status mint. */
	public function test_an_order_pay_status_only_gateway_in_the_window_stamps_after_the_mint(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_payment_method( 'bacs' );
		$order->save();

		// Act: the offline gateway lands processing inside the order-pay window.
		$this->in_pos_request(
			static function () use ( $order ): void {
				do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
				try {
					wc_get_order( $order->get_id() )->update_status( 'processing' );
				} finally {
					apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
				}
			}
		);

		// Assert.
		$rows    = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertCount( 1, $history );
		$this->assertSame( array( $rows[0]['id'] ), $history[0]['payment_ids'] );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** A refused row on the order does not stand in for the tender: the entry names the paying row only. */
	public function test_a_failed_row_then_a_paying_submit_stamps_with_the_paying_row_only(): void {
		// Arrange: a cash attempt refused for exceeding the balance.
		$order   = $this->create_pos_order();
		$refused = $this->record( $order, '100.00' );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( array( 'failed' ), array_column( Ledger::instance()->read( wc_get_order( $order->get_id() ) ), 'status' ) );
		$attempt = wp_generate_uuid4();

		// Act.
		$response = $this->submit( $order, $attempt, 'wcpos_stamp_paid', $this->provenance( self::REGISTER_A, self::SESSION_A ) );

		// Assert.
		$this->assertSame( 'recorded', $response->get_data()['outcome'] );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( array( $attempt ), $history[0]['payment_ids'] );
	}

	/** The fiscal trigger fires while the completion still holds the order lock. */
	public function test_the_sale_completed_action_fires_under_the_order_lock(): void {
		// Arrange.
		$order    = $this->create_pos_order();
		$held     = array();
		$listener = static function ( $completed ) use ( &$held ): void {
			$other = new \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock();
			$taken = $other->acquire( $completed->get_id() );
			if ( $taken ) {
				$other->release( $completed->get_id() );
			}
			$held[] = ! $taken;
		};
		add_action( 'wcpos_sale_completed', $listener, 1 );

		// Act: complete outside any route lock (a webhook's payment_complete()).
		Ledger_Seed::rows( $order, array( $this->cash_row( '92.95' ) ), false );
		wc_get_order( $order->get_id() )->payment_complete();
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.
		remove_action( 'wcpos_sale_completed', $listener, 1 );

		// Assert.
		$this->assertSame( array( true ), $held );
	}

	/** A listener that throws neither breaks the completion nor leaves the lock held. */
	public function test_a_throwing_sale_completed_listener_leaves_the_entry_and_releases_the_lock(): void {
		// Arrange.
		$order    = $this->create_pos_order();
		$listener = static function (): void {
			throw new \RuntimeException( 'listener broke' );
		};
		add_action( 'wcpos_sale_completed', $listener, 1 );

		// Act.
		Ledger_Seed::rows( $order, array( $this->cash_row( '92.95' ) ), false );
		wc_get_order( $order->get_id() )->payment_complete();
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.
		remove_action( 'wcpos_sale_completed', $listener, 1 );

		// Assert.
		$this->assertCount( 1, $this->stored_history( $order->get_id() ) );
		$other = new \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock();
		$this->assertTrue( $other->acquire( $order->get_id() ) );
		$other->release( $order->get_id() );
	}

	/** A listener that reopens and edits the order mid-write cannot change record n's document. */
	public function test_an_edit_during_the_sale_write_does_not_reach_record_n(): void {
		// Arrange: two lines, 60.00 + 32.95.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		foreach ( array( '60.00', '32.95' ) as $price ) {
			$product = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product();
			$product->set_regular_price( $price );
			$product->set_price( $price );
			$product->set_tax_status( 'none' );
			$product->save();
			$order->add_product( $product, 1 );
		}
		$order->calculate_totals( false );
		$order->save();
		$edit = static function ( $snapshot, $order_id ) {
			$editing = wc_get_order( $order_id );
			$editing->set_status( 'pos-open' );
			$lines = array_keys( $editing->get_items() );
			$editing->remove_item( $lines[1] );
			$editing->calculate_totals( false );
			$editing->save();
			return $snapshot;
		};
		add_filter( 'wcpos_fiscal_snapshot_enrich', $edit, 10, 2 );

		// Act.
		try {
			$this->record( $order, '92.95' );
		} finally {
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $edit, 10 );
		}

		// Assert: the document is the completed sale's, not the edited order's.
		$sale = $this->only_sale( $order->get_id() );
		$this->assertCount( 2, $sale['payload']['lines'] );
		$this->assertEquals( 92.95, $sale['payload']['totals']['total_incl'] );
		$this->assertSame( '60.00', wc_get_order( $order->get_id() )->get_total() );
	}

	/** A money command on POS order A is no register origin for storefront order B paid in the same request. */
	public function test_a_storefront_order_paid_during_another_orders_completion_is_not_stamped(): void {
		// Arrange.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$pos        = $this->create_pos_order();
		$storefront = OrderHelper::create_order();
		$storefront->set_created_via( 'checkout' );
		$storefront->set_payment_method( 'bacs' );
		$storefront->save();
		$listener = static function ( $id ) use ( $pos, $storefront ): void {
			if ( (int) $id === $pos->get_id() ) {
				wc_get_order( $storefront->get_id() )->payment_complete( 'txn_elsewhere' );
			}
		};
		add_action( 'woocommerce_payment_complete', $listener, 20 );

		// Act.
		$this->record( $pos, '92.95', $this->provenance( self::REGISTER_A, self::SESSION_A ) );
		remove_action( 'woocommerce_payment_complete', $listener, 20 );

		// Assert.
		$this->assertTrue( wc_get_order( $storefront->get_id() )->is_paid() );
		$this->assertSame( array(), $this->stored_history( $storefront->get_id() ) );
		$this->assertNull( ( new Fiscal_Record_Store() )->find_sale( $storefront->get_id() ) );
		$this->assertCount( 1, $this->stored_history( $pos->get_id() ) );
	}

	/** Moving between two completed statuses (processing to a configured on-hold) is no reopen. */
	public function test_a_move_between_completed_statuses_does_not_reopen_the_sale(): void {
		// Arrange: Cash's POS Order Status stored as On hold; the sale completes onto it.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$this->configure( array( 'pos_cash' => 'wc-on-hold' ) );
		$order = $this->create_pos_order();
		$this->record( $order, '92.95', $this->provenance( self::REGISTER_A, self::SESSION_A ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );

		// Act: wp-admin moves it to processing and back to on-hold, then the till closes it.
		foreach ( array( 'processing', 'on-hold' ) as $status ) {
			$moved = wc_get_order( $order->get_id() );
			$moved->set_status( $status );
			$moved->save();
		}
		$history = $this->stored_history( $order->get_id() );
		$close   = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/close' );
		$close->set_body_params( $this->envelope( $order ) );
		$closed = $this->server->dispatch( $close );

		// Assert.
		$this->assertCount( 1, $history );
		$this->assertNull( $history[0]['reopened_at_gmt'] );
		$this->assertSame( 200, $closed->get_status() );
		$this->assertSame( 1, $closed->get_data()['completion']['n'] );
		$this->assertCount( 1, $this->stored_history( $order->get_id() ) );
		$this->assertCount( 1, ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id(), 'type' => 'sale' ) ) );
	}

	/** A storefront order paid online has no register origin and is never stamped. */
	public function test_a_storefront_order_paid_online_writes_no_entry(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_created_via( 'checkout' );
		$order->set_payment_method( 'bacs' );
		$order->save();

		// Act.
		wc_get_order( $order->get_id() )->payment_complete( 'txn_online' );

		// Assert.
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( array(), $this->stored_history( $order->get_id() ) );
		$this->assertSame( array(), $this->announced );
	}

	/** An unpaid settled status (BACS stored as on-hold) has no date_paid and is no completion. */
	public function test_an_unpaid_settled_status_writes_no_entry(): void {
		// Arrange.
		$this->configure( array( 'wcpos_stamp_sent' => 'wc-on-hold' ) );
		Stamp_Sent_Gateway::$land_status = 'on-hold';
		$order                           = $this->create_pos_order();

		// Act.
		$response = $this->submit( $order, wp_generate_uuid4(), 'wcpos_stamp_sent', $this->provenance( self::REGISTER_A, self::SESSION_A ) );

		// Assert.
		$this->assertSame( 'settled', $response->get_data()['outcome'] );
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );
		$this->assertSame( array(), $this->stored_history( $order->get_id() ) );
		$this->assertSame( array(), $this->announced );
	}

	/** An asynchronous order-pay completion is stamped once the webview row is minted. */
	public function test_an_async_order_pay_completion_is_stamped_after_the_row_is_minted(): void {
		// Arrange: a POS order, no order-pay window, no rows.
		$order = $this->create_pos_order();
		$order->set_payment_method( 'pos_card' );
		$order->save();

		// Act: a webhook in a later request completes the payment.
		wc_get_order( $order->get_id() )->payment_complete( 'ch_async' );
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.

		// Assert.
		$rows    = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertCount( 1, $history );
		$this->assertSame( array( $rows[0]['id'] ), $history[0]['payment_ids'] );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** An asynchronous order-pay payment whose row is not stored completes nothing; it completes once the row lands. */
	public function test_an_async_order_pay_completion_waits_for_a_row_whose_mint_failed(): void {
		// Arrange: a POS order, no rows; the first mint's ledger write never reaches storage.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$order = $this->create_pos_order();
		$order->set_payment_method( 'pos_card' );
		$order->save();
		$lose = static fn( $sql ) => is_string( $sql ) && false !== strpos( $sql, Ledger::META_KEY ) && preg_match( '/^\s*(INSERT|UPDATE)/i', $sql ) ? 'SELECT 0' : $sql;
		add_filter( 'query', $lose );

		// Act: the webhook completes the payment; its mint fails at the request's end.
		try {
			wc_get_order( $order->get_id() )->payment_complete( 'ch_async' );
			Ledger::flush_all();
		} finally {
			remove_filter( 'query', $lose );
		}
		$lost = $this->stored_history( $order->get_id() );
		// The merchant reconciles the payment the failure note names; the next write on the sale completes it.
		Webview_Passthrough::mint( wc_get_order( $order->get_id() ), array( 'provider_refs' => array( 'transaction_id' => 'ch_async' ) ) );
		Ledger::instance()->write( $order->get_id() );

		// Assert.
		$rows    = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$history = $this->stored_history( $order->get_id() );
		$this->assertSame( array(), $lost, 'No completion without the tender\'s row.' );
		$this->assertCount( 1, $rows );
		$this->assertCount( 1, $history );
		$this->assertSame( array( $rows[0]['id'] ), $history[0]['payment_ids'] );
		$this->assertSame( array( 1 ), $this->announced );
		$this->assertCount( 1, $this->only_sale( $order->get_id() )['payload']['payments'] );
	}

	/** A storefront order paid through the order-pay window is not a register sale. */
	public function test_a_storefront_order_pay_payment_in_the_window_writes_no_entry(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_created_via( 'checkout' );
		$order->set_payment_method( 'bacs' );
		$order->save();

		// Act.
		do_action( 'woocommerce_before_pay_action', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		try {
			wc_get_order( $order->get_id() )->payment_complete( 'txn_pay_page' );
		} finally {
			apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
		}

		// Assert.
		$this->assertSame( array(), $this->stored_history( $order->get_id() ) );
		$this->assertSame( array(), $this->announced );
	}

	/** A merchant's non-core POS status (on-hold) with date_paid is a completion. */
	public function test_a_configured_non_core_completion_status_is_stamped(): void {
		// Arrange.
		$this->configure( array( 'pos_cash' => 'wc-on-hold' ) );
		$order = $this->create_pos_order();

		// Act.
		$this->record( $order, '92.95', $this->provenance( self::REGISTER_A, self::SESSION_A ) );

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $stored->get_status() );
		$this->assertNotNull( $stored->get_date_paid( 'edit' ) );
		$this->assertCount( 1, $this->stored_history( $order->get_id() ) );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/** Two callbacks that both loaded the reopened sale write entry 2 once. */
	public function test_two_stale_callback_transitions_write_one_entry(): void {
		// Arrange: completed, then reopened (entry 1 marked).
		$order = $this->create_pos_order();
		$this->record( $order, '92.95', $this->provenance( self::REGISTER_A, self::SESSION_A ) );
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$first  = wc_get_order( $order->get_id() );
		$second = wc_get_order( $order->get_id() );

		// Act.
		$first->payment_complete();
		$second->payment_complete();
		Ledger::flush_all(); // The request ends: what its hooks deferred runs at shutdown.

		// Assert.
		$history = $this->stored_history( $order->get_id() );
		$this->assertSame( array( 1, 2 ), array_column( $history, 'n' ) );
		$this->assertNotNull( $history[0]['reopened_at_gmt'] );
		$this->assertNull( $history[1]['reopened_at_gmt'] );
		$this->assertSame( array( 1, 2 ), $this->announced );
	}

	/** Fulfilment (processing to completed) is not a completion and not a reopen. */
	public function test_a_fulfilment_status_change_writes_nothing(): void {
		// Arrange.
		$this->configure( array( 'pos_cash' => 'wc-processing' ) );
		$order = $this->create_pos_order();
		$this->record( $order, '92.95', $this->provenance( self::REGISTER_A, self::SESSION_A ) );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );

		// Act.
		$fulfilled = wc_get_order( $order->get_id() );
		$fulfilled->set_status( 'completed' );
		$fulfilled->save();

		// Assert.
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertNull( $history[0]['reopened_at_gmt'] );
		$this->assertSame( array( 1 ), $this->announced );
	}

	/**
	 * Add the gateway fixtures.
	 *
	 * @param array $gateways Gateway classes.
	 */
	public function gateways( array $gateways ): array {
		return array_merge( $gateways, array( Stamp_Sent_Gateway::class, Stamp_Paid_Gateway::class ) );
	}

	/**
	 * The fixtures are full-order gateways.
	 *
	 * @param string $mode    Capture mode.
	 * @param object $gateway Gateway.
	 */
	public function mode( $mode, $gateway ): string {
		return in_array( $gateway->id, array( 'wcpos_stamp_sent', 'wcpos_stamp_paid' ), true ) ? 'gateway' : $mode;
	}

	/**
	 * The fixtures declare no fields.
	 *
	 * @param mixed  $fields  Declared fields.
	 * @param object $gateway Gateway.
	 * @return mixed
	 */
	public function fields( $fields, $gateway ) {
		if ( ! in_array( $gateway->id, array( 'wcpos_stamp_sent', 'wcpos_stamp_paid' ), true ) ) {
			return $fields;
		}
		return array(
			'verb'       => array(
				'kind'  => 'send',
				'label' => 'Send',
			),
			'components' => array(),
		);
	}

	/**
	 * Re-enable the fixtures after Free's own settings filter has disabled them.
	 *
	 * @param mixed $options The gateway options.
	 * @return mixed
	 */
	public function keep_fixtures_enabled( $options ) {
		foreach ( array( 'wcpos_stamp_sent', 'wcpos_stamp_paid' ) as $id ) {
			if ( isset( $options['gateways'][ $id ] ) ) {
				$options['gateways'][ $id ]['enabled'] = self::$fixtures_enabled;
			}
		}
		return $options;
	}

	/**
	 * Store the POS gateway settings, with an optional POS Order Status per gateway.
	 *
	 * @param array<string, string> $order_statuses Stored status per gateway id, with the `wc-` prefix.
	 */
	protected function configure( array $order_statuses = array() ): void {
		$gateways = array();
		foreach ( array( 'pos_cash', 'pos_card', 'wcpos_stamp_sent', 'wcpos_stamp_paid' ) as $id ) {
			$gateways[ $id ] = array( 'enabled' => true );
			if ( isset( $order_statuses[ $id ] ) ) {
				$gateways[ $id ]['order_status'] = $order_statuses[ $id ];
			}
		}
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => $gateways,
			)
		);
		remove_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'keep_fixtures_enabled' ), 20 );
		add_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'keep_fixtures_enabled' ), 20 );
	}

	/**
	 * A captured cash row for seeding without deriving.
	 *
	 * @param string $amount Amount.
	 */
	protected function cash_row( string $amount ): array {
		return array(
			'id'           => wp_generate_uuid4(),
			'method_id'    => 'pos_cash',
			'kind'         => 'cash',
			'capture_mode' => 'manual',
			'amount'       => $amount,
			'status'       => 'captured',
		);
	}

	/** An open POS order for 92.95. */
	protected function create_pos_order(): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();
		return $order;
	}

	/**
	 * A provenance body.
	 *
	 * @param string $register Register uuid.
	 * @param string $session  Session uuid.
	 */
	protected function provenance( string $register, string $session ): array {
		return array(
			'register_id'  => $register,
			'session_id'   => $session,
			'sale_time'    => '2026-10-20T09:15:00+02:00',
			'sale_tz'      => 'Europe/Berlin',
			'sale_counter' => '42',
		);
	}

	/**
	 * Record cash through the route.
	 *
	 * @param \WC_Order  $order      Order.
	 * @param string     $amount     Amount.
	 * @param array|null $provenance Provenance body, if any.
	 */
	protected function record( \WC_Order $order, string $amount, ?array $provenance = null ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
		$body    = array(
			'payment' => array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => $amount,
			),
		);
		if ( null !== $provenance ) {
			$body['provenance'] = $provenance;
		}
		$request->set_body_params( $this->envelope( $order, $body ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Submit a gateway attempt through the route.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $attempt    Attempt uuid.
	 * @param string    $method     Gateway id.
	 * @param array     $provenance Provenance body.
	 */
	protected function submit( \WC_Order $order, string $attempt, string $method, array $provenance ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/' . $method . '/submit' );
		$request->set_body_params(
			$this->envelope(
				$order,
				array(
					'attempt_id' => $attempt,
					'values'     => array(),
					'provenance' => $provenance,
				)
			)
		);
		return $this->server->dispatch( $request );
	}

	/**
	 * The stored completion history, read from storage.
	 *
	 * @param int $order_id Order ID.
	 */
	protected function stored_history( int $order_id ): array {
		$value = $this->stored_meta( $order_id, Completion_Stamp::META_KEY );
		return null === $value ? array() : json_decode( $value, true );
	}

	/**
	 * One stored meta value, read through the data store.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Meta key.
	 */
	protected function stored_meta( int $order_id, string $key ): ?string {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( $key === $meta->meta_key ) {
				return (string) maybe_unserialize( $meta->meta_value );
			}
		}
		return null;
	}

	/**
	 * Run a callback as a POS request.
	 *
	 * @param callable $callback Work.
	 */
	protected function in_pos_request( callable $callback ): void {
		$previous                           = $GLOBALS['wp']->query_vars;
		$GLOBALS['wp']->query_vars['wcpos'] = 1;
		try {
			$callback();
		} finally {
			$GLOBALS['wp']->query_vars = $previous;
		}
	}
}

/** A gateway that sends an invoice and takes no money. */
class Stamp_Sent_Gateway extends \WC_Payment_Gateway {
	/**
	 * The status process_payment() leaves the order in.
	 *
	 * @var string
	 */
	public static $land_status = 'pending';

	/** Fixture identity. */
	public function __construct() {
		$this->id      = 'wcpos_stamp_sent';
		$this->title   = 'Invoice';
		$this->enabled = 'yes';
	}

	/**
	 * Leave the order awaiting payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_payment( $order_id ) {
		wc_get_order( $order_id )->update_status( self::$land_status, 'Invoice sent' );
		return array( 'result' => 'success' );
	}
}

/** A gateway that takes the money. */
class Stamp_Paid_Gateway extends \WC_Payment_Gateway {
	/**
	 * Whether to land a paid status instead of calling payment_complete().
	 *
	 * @var bool
	 */
	public static $status_only = false;

	/** Fixture identity. */
	public function __construct() {
		$this->id      = 'wcpos_stamp_paid';
		$this->title   = 'Paid';
		$this->enabled = 'yes';
	}

	/**
	 * Take payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( self::$status_only ) {
			$order->update_status( 'processing' );
		} else {
			$order->payment_complete( 'stamp-transaction' );
		}
		return array( 'result' => 'success' );
	}
}
