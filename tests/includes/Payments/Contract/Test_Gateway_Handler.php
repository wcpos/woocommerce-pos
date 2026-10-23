<?php
/**
 * Gateway capture handler tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Handler;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Gateway mode has fixed full-order capabilities. */
class Test_Gateway_Handler extends WCPOS_REST_Unit_Test_Case {
	/** Refund capability follows WooCommerce. */
	public function test_gateway_description_has_fixed_capabilities(): void {
		// Arrange.
		$gateway = new Gateway_Handler_Test_Gateway();
		$handler = Capture_Mode_Registry::instance()->get( 'gateway' );
		$this->assertInstanceOf( Gateway_Handler::class, $handler );
		foreach ( array( false, true ) as $refunds ) {
			$gateway->supports = $refunds ? array( 'refunds' ) : array();
			// Act.
			$description = $handler->describe( $gateway );
			// Assert.
			$this->assertSame(
				array(
					'mode' => 'gateway',
					'provider' => null,
					'hardware' => null,
					'webview_available' => true,
				),
				$description['capture']
			);
			$this->assertSame(
				array(
					'amount' => array( 'partial' => false ),
					'change' => false,
					'refunds' => array(
						'via' => $refunds ? 'provider' : 'manual',
						'partial' => $refunds,
					),
					'tips' => 'none',
					'offline' => 'none',
					'void' => false,
				),
				$description['capabilities']
			);
			$this->assertSame( array(), $description['provider_data'] );
		}
		$this->assertSame( 'wcpos_invalid_transition', $handler->void( array(), '' )->get_error_code() );
		$this->assertSame( 409, $handler->void( array(), '' )->get_error_data()['status'] );
	}

	/** Mode opt-in retains the Legacy tab. */
	public function test_gateway_filter_opts_into_gateway_mode(): void {
		// Arrange.
		$filter = static fn() => 'gateway';
		add_filter( 'wcpos_payment_method_capture_mode', $filter );
		try {
			// Act.
			$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );
			// Assert.
			$this->assertSame( 'gateway', $descriptor['capture']['mode'] );
			$this->assertTrue( $descriptor['capture']['webview_available'] );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter );
		}
	}

	/** The scoped key wins for its gateway; every other gateway-mode method keeps Free's handler. */
	public function test_gateway_scoped_registration_serves_one_gateway(): void {
		// Arrange.
		$registry = Capture_Mode_Registry::instance();
		$filter   = static fn( $mode, $gateway ) => 'pos_cash' === $gateway->id ? 'gateway:pos_cash' : ( 'pos_card' === $gateway->id ? 'gateway' : $mode );
		add_filter( 'wcpos_payment_method_capture_mode', $filter, 10, 2 );
		try {
			$registry->register( 'gateway:pos_cash', Replacement_Gateway_Handler::class );
			// Act.
			$cash = Descriptor_Builder::instance()->get( 'pos_cash' );
			$card = Descriptor_Builder::instance()->get( 'pos_card' );
			// Assert.
			$this->assertSame( 'gateway', $cash['capture']['mode'] );
			$this->assertFalse( $cash['capture']['webview_available'] );
			$this->assertSame( 'gateway', $card['capture']['mode'] );
			$this->assertTrue( $card['capture']['webview_available'] );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter, 10 );
			$registry->register( 'gateway:pos_cash', Gateway_Handler::class );
		}
	}

	/** Refunds run through the gateway's own process_refund() when it supports them, else succeed by hand. */
	public function test_gateway_refund_follows_the_gateway(): void {
		// Arrange.
		$filter = static fn( array $gateways ): array => array_merge( $gateways, array( Refunding_Test_Gateway::class, No_Refund_Test_Gateway::class ) );
		add_filter( 'woocommerce_payment_gateways', $filter );
		\WC_Payment_Gateways::instance()->init();
		$handler = Capture_Mode_Registry::instance()->get( 'gateway' );
		$order   = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$order->set_transaction_id( 'ch_original_charge' );
		$order->save();
		$row = array(
			'order_id'  => $order->get_id(),
			'method_id' => 'wcpos_refunding_test',
			'refunds'   => array(),
		);
		try {
			// Act / Assert: provider refund succeeds.
			Refunding_Test_Gateway::$result = true;
			$refunded                       = $handler->refund( $row, 77, '5.00' );
			$this->assertSame( array( $order->get_id(), 5.0, '' ), Refunding_Test_Gateway::$args );
			$this->assertSame( 'succeeded', $refunded['refunds'][0]['status'] );
			$this->assertSame( '5.00', $refunded['refunds'][0]['amount'] );
			$this->assertSame( 77, $refunded['refunds'][0]['id'] );
			$this->assertNull( $refunded['refunds'][0]['provider_ref'] );
			// Act / Assert: any truthy answer is a success, as wc_refund_payment() reads it.
			Refunding_Test_Gateway::$result = 1;
			$this->assertSame( 'succeeded', $handler->refund( $row, 80, '5.00' )['refunds'][0]['status'] );
			// Act / Assert: the provider refuses, with and without a message.
			Refunding_Test_Gateway::$result = new \WP_Error( 'declined', 'No funds' );
			$error                          = $handler->refund( $row, 78, '5.00' );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( 'wcpos_provider_error', $error->get_error_code() );
			$this->assertSame( 'No funds', $error->get_error_message() );
			Refunding_Test_Gateway::$result = false;
			$this->assertSame( 'wcpos_provider_error', $handler->refund( $row, 81, '5.00' )->get_error_code() );
			// Act / Assert: a registered gateway without refunds is handed back by hand.
			$manual = $handler->refund( array_merge( $row, array( 'method_id' => 'wcpos_norefund_test' ) ), 79, '5.00' );
			$this->assertSame( 'succeeded', $manual['refunds'][0]['status'] );
			$this->assertNull( $manual['refunds'][0]['provider_ref'] );
			// Act / Assert: a gateway that is no longer installed cannot give anything back.
			$missing = $handler->refund( array_merge( $row, array( 'method_id' => 'wcpos_gone' ) ), 82, '5.00' );
			$this->assertSame( 'wcpos_payment_method_not_found', $missing->get_error_code() );
		} finally {
			remove_filter( 'woocommerce_payment_gateways', $filter );
			\WC_Payment_Gateways::instance()->init();
		}
	}

	/** §6.6 pin 11: a gateway's indeterminate refund error keeps its flag through the handler; a definite one has none. */
	public function test_gateway_refund_carries_the_gateways_indeterminate_flag(): void {
		// Arrange.
		$filter = static fn( array $gateways ): array => array_merge( $gateways, array( Refunding_Test_Gateway::class ) );
		add_filter( 'woocommerce_payment_gateways', $filter );
		\WC_Payment_Gateways::instance()->init();
		$handler = Capture_Mode_Registry::instance()->get( 'gateway' );
		$order   = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$row     = array(
			'order_id'  => $order->get_id(),
			'method_id' => 'wcpos_refunding_test',
			'refunds'   => array(),
		);
		try {
			// Act.
			Refunding_Test_Gateway::$result = new \WP_Error(
				'timeout',
				'Provider timed out',
				array( 'indeterminate' => true )
			);
			$unknown                        = $handler->refund( $row, 83, '5.00' );
			Refunding_Test_Gateway::$result = new \WP_Error( 'declined', 'No funds', array( 'indeterminate' => false ) );
			$declined                       = $handler->refund( $row, 84, '5.00' );

			// Assert.
			$this->assertSame( 'wcpos_provider_error', $unknown->get_error_code() );
			$this->assertSame( 'Provider timed out', $unknown->get_error_message() );
			$this->assertSame(
				array(
					'status'        => 502,
					'indeterminate' => true,
				),
				$unknown->get_error_data()
			);
			$this->assertSame( 'wcpos_provider_error', $declined->get_error_code() );
			$this->assertSame( array( 'status' => 502 ), $declined->get_error_data() );
		} finally {
			Refunding_Test_Gateway::$result = true;
			remove_filter( 'woocommerce_payment_gateways', $filter );
			\WC_Payment_Gateways::instance()->init();
		}
	}

	/** An attempt's paid facts are attributed only when the order is paid through its method and no counting row covers it. */
	public function test_gateway_status_attributes_paid_facts_only_when_the_rows_do_not_cover_the_attempts_method(): void {
		// Arrange: a POS order a gateway paid outside the ledger.
		$handler = Capture_Mode_Registry::instance()->get( 'gateway' );
		$order   = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_total( '92.95' );
		$order->set_payment_method( 'wcpos_paid_test' );
		$order->set_transaction_id( 'txn_paid' );
		$order->set_date_paid( time() );
		$order->set_status( 'processing' );
		$order->save();
		$attempt = array(
			'id'           => wp_generate_uuid4(),
			'status'       => 'pending',
			'capture_mode' => 'gateway',
			'method_id'    => 'wcpos_paid_test',
			'order_id'     => $order->get_id(),
		);

		// Act.
		$paid      = $handler->status( $attempt );
		$elsewhere = $handler->status( array_merge( $attempt, array( 'method_id' => 'wcpos_sent_test' ) ) );
		$webview   = $handler->status( array_merge( $attempt, array( 'capture_mode' => 'webview' ) ) );
		\WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed::rows(
			$order,
			array(
				array(
					'id'           => wp_generate_uuid4(),
					'method_id'    => 'pos_cash',
					'capture_mode' => 'manual',
					'amount'       => '92.95',
					'status'       => 'captured',
				),
			)
		);
		$covered = $handler->status( $attempt );

		// Assert.
		$this->assertSame( array( 'captured', '92.95', 'txn_paid' ), array( $paid['status'], $paid['amount'], $paid['provider_refs']['transaction_id'] ) );
		$this->assertSame( $attempt['id'], $paid['id'] );
		$this->assertSame( 'pending', $elsewhere['status'] );
		$this->assertSame( 'pending', $webview['status'] );
		$this->assertSame( 'pending', $covered['status'] );
	}

	/** An unpaid order gives the attempt no answer. */
	public function test_gateway_status_leaves_an_attempt_on_an_unpaid_order_unchanged(): void {
		// Arrange.
		$order = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$order->set_payment_method( 'wcpos_paid_test' );
		$order->save();
		$attempt = array(
			'id'           => wp_generate_uuid4(),
			'status'       => 'pending',
			'capture_mode' => 'gateway',
			'method_id'    => 'wcpos_paid_test',
			'order_id'     => $order->get_id(),
		);

		// Act.
		$answer = Capture_Mode_Registry::instance()->get( 'gateway' )->status( $attempt );

		// Assert.
		$this->assertSame( $attempt, $answer );
	}

	/** Addendum: specialization uses registration, not a describe filter. */
	public function test_gateway_second_registration_replaces_handler(): void {
		// Arrange.
		$registry = Capture_Mode_Registry::instance();
		$original = get_class( $registry->get( 'gateway' ) );
		try {
			// Act.
			$registry->register( 'gateway', Replacement_Gateway_Handler::class );
			// Assert.
			$this->assertSame( array( 'replacement' => true ), $registry->get( 'gateway' )->describe( new Gateway_Handler_Test_Gateway() ) );
		} finally {
			$registry->register( 'gateway', $original );
		}
	}
}

/** Test-only replacement. */
class Replacement_Gateway_Handler extends Abstract_Capture_Mode_Handler {
	/**
	 * Identify the replacement.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array( 'replacement' => true );
	}
}

/** Plain Woo gateway fixture. */
class Gateway_Handler_Test_Gateway extends \WC_Payment_Gateway {}

/** A registered gateway with no refund support. */
class No_Refund_Test_Gateway extends \WC_Payment_Gateway {
	/** Register fixture identity. */
	public function __construct() {
		$this->id       = 'wcpos_norefund_test';
		$this->title    = 'No refunds';
		$this->supports = array( 'products' );
	}
}

/** A gateway that supports refunds and records what it was asked. */
class Refunding_Test_Gateway extends \WC_Payment_Gateway {
	/**
	 * What process_refund() answers.
	 *
	 * @var mixed
	 */
	public static $result = true;
	/**
	 * The arguments process_refund() received.
	 *
	 * @var array
	 */
	public static $args = array();

	/** Register fixture identity. */
	public function __construct() {
		$this->id       = 'wcpos_refunding_test';
		$this->title    = 'Refunding';
		$this->supports = array( 'products', 'refunds' );
	}

	/**
	 * Record the refund request.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Amount.
	 * @param string $reason   Reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		self::$args = array( $order_id, $amount, $reason );
		return self::$result;
	}
}
