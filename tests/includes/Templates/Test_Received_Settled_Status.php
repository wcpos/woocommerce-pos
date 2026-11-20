<?php
/**
 * Tests for the received page honouring a gateway's configured POS order status.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Attempts;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Templates\Received;
use WC_Order;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * The received page emits the payment-received message when the order has reached
 * the status the merchant explicitly configured for its gateway, not only when
 * WooCommerce calls the order paid.
 *
 * A "Pay by invoice" workflow stores Pending payment for BACS so the customer can
 * still pay through the web pay link. WooCommerce never calls such an order paid,
 * so the gate on is_paid() alone left the till hanging on a sale the merchant
 * considers finished.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Received_Settled_Status extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'woocommerce_pos_settings_payment_gateways' );
		delete_option( 'woocommerce_pos_settings_checkout' );
		parent::tearDown();
	}

	/**
	 * A gateway configured to settle on Pending payment emits once the order is pending.
	 */
	public function test_order_at_stored_gateway_status_renders_received_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-pending' );
		$order = $this->create_pos_order( 'bacs', 'pending' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringContainsString( "action: 'wcpos-payment-received'", $output );
		$this->assertSame( 1, preg_match( '/var order = (.+);/', $output, $matches ) );
		$payload = json_decode( $matches[1], true );
		$this->assertSame( $order->get_id(), $payload['id'] );
		$this->assertSame( 'pending', $payload['status'] );
	}

	/**
	 * On hold is not a WooCommerce paid status either; the configured status restores
	 * the direct message for the default BACS/cheque configuration.
	 */
	public function test_order_at_stored_on_hold_status_renders_received_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'cheque', 'wc-on-hold' );
		$order = $this->create_pos_order( 'cheque', 'on-hold' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * An async gateway configured to settle on Completed that redirects while the
	 * order is still pending has not reached its configured status: no emission.
	 */
	public function test_pending_order_for_gateway_stored_completed_does_not_render_received_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'acme_async', 'wc-completed' );
		$order = $this->create_pos_order( 'acme_async', 'pending' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * A stored status on a gateway the merchant disabled for POS is not intent.
	 */
	public function test_pending_order_for_gateway_disabled_for_pos_does_not_render_received_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-pending', false );
		$order = $this->create_pos_order( 'bacs', 'pending' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * Without Pro the free plugin restricts POS gateways to cash and card, so a
	 * stored BACS status cannot be acted on. Nothing changes for free-only sites.
	 */
	public function test_pending_order_for_gateway_restricted_by_free_plugin_does_not_render_received_script(): void {
		// Arrange: the free plugin's option filter stays in place.
		$this->set_gateway_settings( 'bacs', 'wc-pending' );
		$order = $this->create_pos_order( 'bacs', 'pending' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * A failed payment must stay open for a retry even if the merchant stored
	 * Failed as the gateway's status — the picker offers every registered status.
	 */
	public function test_failed_order_with_failed_stored_as_gateway_status_does_not_render_received_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-failed' );
		$order = $this->create_pos_order( 'bacs', 'failed' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * A refund is money given back, never a settled sale, even if stored.
	 */
	public function test_refunded_order_with_refunded_stored_as_gateway_status_does_not_render_received_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-refunded' );
		$order = $this->create_pos_order( 'bacs', 'refunded' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * The configured status closes POS sales only. A web-checkout order reached
	 * through this URL with its key keeps WooCommerce's own thank-you page.
	 */
	public function test_non_pos_order_at_stored_gateway_status_does_not_render_received_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-pending' );
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'bacs',
				'status'         => 'pending',
				'total'          => '50',
			)
		);

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * The parked POS statuses never count as settled, whatever is stored.
	 *
	 * @dataProvider parked_pos_statuses
	 *
	 * @param string $status Parked status.
	 */
	public function test_parked_pos_status_never_renders_received_script( string $status ): void {
		// Arrange.
		$this->set_gateway_settings( 'pos_cash', 'wc-' . $status );
		$order = $this->create_pos_order( 'pos_cash', $status );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * Parked POS statuses.
	 *
	 * @return array<string, array{string}>
	 */
	public function parked_pos_statuses(): array {
		return array(
			'pos-open'    => array( 'pos-open' ),
			'pos-partial' => array( 'pos-partial' ),
		);
	}

	/**
	 * A site upgraded from before per-gateway statuses may hold its only configured
	 * status in the legacy global checkout option; that counts as intent too.
	 */
	public function test_legacy_global_checkout_status_counts_as_stored_gateway_status(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'bacs' => array(
						'order'   => 0,
						'enabled' => true,
					),
				),
			)
		);
		update_option( 'woocommerce_pos_settings_checkout', array( 'order_status' => 'wc-pending' ) );
		$order = $this->create_pos_order( 'bacs', 'pending' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * A WooCommerce paid status still emits with no POS configuration at all.
	 */
	public function test_paid_order_with_no_stored_status_still_renders_received_script(): void {
		// Arrange.
		$order = $this->create_pos_order( 'bacs', 'processing' );

		// Act.
		$output = $this->render_received( $order );

		// Assert.
		$this->assertStringContainsString( "action: 'wcpos-payment-received'", $output );
	}

	/**
	 * A send-declaring gateway run through the Legacy pay window leaves the order
	 * pending: the page writes the awaiting-customer stamp once and posts it.
	 */
	public function test_send_declaring_gateway_pending_order_writes_awaiting_stamp_and_renders_sent_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-completed' );
		$fields = static function ( $block, $gateway ) {
			return 'bacs' === $gateway->id
				? array(
					'verb'       => array(
						'kind'  => 'send',
						'label' => 'Send invoice',
					),
					'components' => array(),
				)
				: $block;
		};
		add_filter( 'wcpos_payment_method_fields', $fields, 10, 2 );
		$order = $this->create_pos_order( 'bacs', 'pending' );
		$cashier_id = self::factory()->user->create();
		$order->set_billing_email( 'customer@example.com' );
		$order->update_meta_data( '_pos_user', $cashier_id );
		$order->save();
		wp_set_current_user( 0 );

		try {
			// Act: the second render is a reload.
			$first  = $this->render_received( $order );
			$second = $this->render_received( $order );
		} finally {
			remove_filter( 'wcpos_payment_method_fields', $fields, 10 );
		}

		// Assert.
		foreach ( array( $first, $second ) as $output ) {
			$this->assertStringContainsString( "action: 'wcpos-payment-sent'", $output );
			$this->assertStringNotContainsString( "action: 'wcpos-payment-received'", $output );
		}
		$this->assertSame( 1, preg_match( '/var order = (.+);/', $first, $matches ) );
		$payload = json_decode( $matches[1], true );
		$this->assertSame( $order->get_id(), $payload['id'] );
		$this->assertContains( Attempts::META_KEY, array_column( $payload['meta_data'], 'key' ) );
		$fresh    = wc_get_order( $order->get_id() );
		$awaiting = Attempts::of( $fresh )->awaiting();
		$this->assertSame( 'bacs', $awaiting['method_id'] );
		$this->assertSame( 'customer@example.com', $awaiting['destination'] );
		$this->assertSame( $cashier_id, $awaiting['cashier_id'] );
		$this->assertCount( 1, Attempts::of( $fresh )->entries() );
		$this->assertSame( 'pending', $fresh->get_status() );
	}

	/**
	 * An overlapping send still posts the payload fetched after its stamp.
	 */
	public function test_send_stamp_written_by_overlapping_request_still_posts_fresh_payload(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-completed' );
		$fields = static function ( $block, $gateway ) {
			return 'bacs' === $gateway->id
				? array(
					'verb'       => array(
						'kind'  => 'send',
						'label' => 'Send invoice',
					),
					'components' => array(),
				)
				: $block;
		};
		$order      = $this->create_pos_order( 'bacs', 'pending' );
		$cashier_id = self::factory()->user->create();
		$order->set_billing_email( 'customer@example.com' );
		$order->update_meta_data( '_pos_user', $cashier_id );
		$order->save();
		wp_set_current_user( 0 );
		$first_fetch = true;
		$overlap     = static function ( $response ) use ( $order, $cashier_id, &$first_fetch ) {
			if ( $first_fetch ) {
				$first_fetch = false;
				Ledger::instance()->write(
					wc_get_order( $order->get_id() ),
					static function ( Ledger_Draft $draft ) use ( $cashier_id ): void {
						$id = wp_generate_uuid4();
						Attempts::open( $draft, $id, 'bacs' );
						$draft->attempt( $id, 'sent', array( 'cashier_id' => $cashier_id ) );
						Attempts::await( $draft, $id, 'customer@example.com' );
					},
					Ledger::MODE_HOLD
				);
			}
			return $response;
		};
		add_filter( 'wcpos_payment_method_fields', $fields, 10, 2 );
		add_filter( 'rest_post_dispatch', $overlap );

		try {
			// Act.
			$output = $this->render_received( $order );
		} finally {
			remove_filter( 'rest_post_dispatch', $overlap );
			remove_filter( 'wcpos_payment_method_fields', $fields, 10 );
		}

		// Assert.
		$this->assertStringContainsString( "action: 'wcpos-payment-sent'", $output );
		$this->assertSame( 1, preg_match( '/var order = (.+);/', $output, $matches ) );
		$payload = json_decode( $matches[1], true );
		$this->assertContains( Attempts::META_KEY, array_column( $payload['meta_data'], 'key' ) );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertCount( 1, Attempts::of( $fresh )->entries() );
	}

	/**
	 * A cancellation before the locked write prevents the stamp and sent event.
	 */
	public function test_send_order_cancelled_before_lock_gets_no_stamp_and_no_sent_script(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-completed' );
		$fields = static function ( $block, $gateway ) {
			return 'bacs' === $gateway->id
				? array(
					'verb'       => array(
						'kind'  => 'send',
						'label' => 'Send invoice',
					),
					'components' => array(),
				)
				: $block;
		};
		$order      = $this->create_pos_order( 'bacs', 'pending' );
		$cashier_id = self::factory()->user->create();
		$order->set_billing_email( 'customer@example.com' );
		$order->update_meta_data( '_pos_user', $cashier_id );
		$order->save();
		wp_set_current_user( 0 );
		$first_fetch = true;
		$cancel      = static function ( $response ) use ( $order, &$first_fetch ) {
			if ( $first_fetch ) {
				$first_fetch = false;
				wc_get_order( $order->get_id() )->update_status( 'cancelled' );
			}
			return $response;
		};
		add_filter( 'wcpos_payment_method_fields', $fields, 10, 2 );
		add_filter( 'rest_post_dispatch', $cancel );

		try {
			// Act.
			$output = $this->render_received( $order );
		} finally {
			remove_filter( 'rest_post_dispatch', $cancel );
			remove_filter( 'wcpos_payment_method_fields', $fields, 10 );
		}

		// Assert.
		$this->assertStringNotContainsString( 'wcpos-payment-sent', $output );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertNull( Attempts::of( $fresh )->awaiting() );
		$this->assertSame( 'cancelled', $fresh->get_status() );
	}

	/**
	 * Simulate Pro, which lifts the free plugin's cash/card-only restriction.
	 */
	private function allow_all_gateways_for_pos(): void {
		remove_all_filters( 'option_woocommerce_pos_settings_payment_gateways' );
	}

	/**
	 * Store POS payment-gateway settings for one gateway.
	 *
	 * @param string $gateway_id   Gateway id.
	 * @param string $order_status Configured status, with the 'wc-' prefix.
	 * @param bool   $enabled      Whether the gateway is enabled for POS.
	 */
	private function set_gateway_settings( string $gateway_id, string $order_status, bool $enabled = true ): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					$gateway_id => array(
						'order'        => 0,
						'enabled'      => $enabled,
						'order_status' => $order_status,
					),
				),
			)
		);
	}

	/**
	 * Create a POS order paid through the given gateway at the given status.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param string $status     Order status without the 'wc-' prefix.
	 */
	private function create_pos_order( string $gateway_id, string $status ): WC_Order {
		$order = OrderHelper::create_order(
			array(
				'payment_method' => $gateway_id,
				'status'         => $status,
				'total'          => '50',
			)
		);
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		return $order;
	}

	/**
	 * Render the real received template without terminating PHPUnit.
	 *
	 * @param WC_Order $order Order to render.
	 *
	 * @return string Rendered HTML.
	 */
	private function render_received( WC_Order $order ): string {
		$template_filter = static function ( $path, $template ) {
			return 'received.php' === $template ? __DIR__ . '/fixtures/received.php' : $path;
		};
		$original_get = $_GET;
		$buffer_level = ob_get_level();

		add_filter( 'wcpos_locate_template', $template_filter, 10, 2 );
		$_GET['key'] = $order->get_order_key();
		ob_start();

		try {
			( new Received( $order->get_id() ) )->get_template();
			return (string) ob_get_clean();
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_GET = $original_get;
			remove_filter( 'wcpos_locate_template', $template_filter, 10 );
		}
	}
}
