<?php
/**
 * Tests for direct submission of POS payment gateways.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Gateways\Card;
use WCPOS\WooCommercePOS\Gateways\Cash;
use WCPOS\WooCommercePOS\Templates\Payment;

/**
 * Payment direct submit tests.
 *
 * @internal
 * @coversNothing
 */
class Test_Payment_Direct_Submit extends WC_Unit_Test_Case {

	/**
	 * Only POS gateway instances bypass submit handlers, in input order.
	 */
	public function test_direct_submit_gateway_ids_mixed_gateways_returns_pos_ids_in_order(): void {
		$order    = OrderHelper::create_order( array( 'status' => 'pending' ) );
		$payment  = new Payment( $order->get_id() );
		$gateways = array(
			'pos_card'    => new Card(),
			'third_party' => new class() extends \WC_Payment_Gateway {},
			'pos_cash'    => new Cash(),
		);

		$ids = $payment->direct_submit_gateway_ids( $gateways );

		$this->assertSame( array( 'pos_card', 'pos_cash' ), $ids );
	}

	/**
	 * No available gateways produces no direct submit IDs.
	 */
	public function test_direct_submit_gateway_ids_empty_input_returns_empty_array(): void {
		$order   = OrderHelper::create_order( array( 'status' => 'pending' ) );
		$payment = new Payment( $order->get_id() );

		$this->assertSame( array(), $payment->direct_submit_gateway_ids( array() ) );
	}

	/**
	 * The shipped template keeps direct submission and both error message paths.
	 */
	public function test_payment_template_contains_direct_submit_and_blocked_message_paths(): void {
		$template = file_get_contents( woocommerce_pos_locate_template( 'payment.php' ) );

		$this->assertStringContainsString( 'directSubmitGateways', $template );
		$this->assertStringContainsString( 'HTMLFormElement.prototype.submit.call(form)', $template );
		$this->assertStringContainsString( 'wcpos-payment-blocked', $template );
		$this->assertStringContainsString( 'window.ReactNativeWebView.postMessage', $template );
	}
}
