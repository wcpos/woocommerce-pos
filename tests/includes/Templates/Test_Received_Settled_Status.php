<?php
/**
 * Tests for the received page honouring a gateway's configured POS order status.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\Templates\Received;
use WC_Order;
use WC_REST_Unit_Test_Case;

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
class Test_Received_Settled_Status extends WC_REST_Unit_Test_Case {
	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $user;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		parent::setUp();
		$this->user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
	}

	/**
	 * Register the WCPOS REST API routes.
	 */
	public function rest_api_init(): void {
		new API();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'woocommerce_pos_settings_payment_gateways' );
		delete_option( 'woocommerce_pos_settings_checkout' );
		remove_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
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

		add_filter( 'woocommerce_pos_locate_template', $template_filter, 10, 2 );
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
			remove_filter( 'woocommerce_pos_locate_template', $template_filter, 10 );
		}
	}
}
