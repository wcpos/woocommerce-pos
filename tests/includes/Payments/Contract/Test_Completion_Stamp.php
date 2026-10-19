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
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** One entry per completion at a register, from every completion path, with its own provenance. */
class Test_Completion_Stamp extends WCPOS_REST_Unit_Test_Case {
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
		Completion_Stamp::reset();
		Stamp_Sent_Gateway::$land_status = 'pending';
		Stamp_Paid_Gateway::$status_only = false;
		$this->announced = array();
		add_action( 'wcpos_sale_completed', array( $this, 'announce' ), 10, 2 );
	}

	/** Remove the listener. */
	public function tearDown(): void {
		remove_action( 'wcpos_sale_completed', array( $this, 'announce' ), 10 );
		remove_filter( 'option_woocommerce_pos_settings_payment_gateways', array( $this, 'keep_fixtures_enabled' ), 20 );
		Completion_Stamp::reset();
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
		Completion_Stamp::reset(); // The callback is a later request.

		// Act: the provider's callback completes the payment.
		wc_get_order( $order->get_id() )->payment_complete( 'txn_callback' );

		// Assert.
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( self::REGISTER_B, $history[0]['register'] );
		$this->assertSame( self::SESSION_B, $history[0]['session'] );
		$this->assertSame( array( 1 ), $this->announced );
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

		// Assert.
		$rows    = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$history = $this->stored_history( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertCount( 1, $history );
		$this->assertSame( array( $rows[0]['id'] ), $history[0]['payment_ids'] );
		$this->assertSame( array( 1 ), $this->announced );
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
		$request->set_body_params( $body );
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
			array(
				'attempt_id' => $attempt,
				'values'     => array(),
				'provenance' => $provenance,
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
