<?php
/**
 * Tests for the cashier line on the POS pay page.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Templates\Payment;

/**
 * Payment cashier details tests.
 *
 * @internal
 * @coversNothing
 */
class Test_Payment_Cashier_Details extends WC_Unit_Test_Case {

	/**
	 * An order with no `_pos_user` meta renders an empty cashier name, with no PHP warning.
	 *
	 * Orders made outside the POS, and orders whose cashier was deleted, reach the pay page
	 * without a user; `get_user_by()` returns false there and the template used to read
	 * `display_name` off it.
	 */
	public function test_missing_pos_user_renders_empty_cashier_name(): void {
		$order = OrderHelper::create_order();
		$order->delete_meta_data( '_pos_user' );
		$order->save();

		$payment = new Payment( $order->get_id() );
		$html    = $payment->get_cashier_details_html();

		$this->assertStringContainsString( 'class="cashier-name"></span>', $html );
	}

	/**
	 * A deleted cashier id renders an empty cashier name too.
	 */
	public function test_deleted_pos_user_renders_empty_cashier_name(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_user', 999999 );
		$order->save();

		$payment = new Payment( $order->get_id() );

		$this->assertStringContainsString( 'class="cashier-name"></span>', $payment->get_cashier_details_html() );
	}

	/**
	 * A real cashier is still named.
	 */
	public function test_pos_user_renders_display_name(): void {
		$user_id = $this->factory()->user->create( array( 'display_name' => 'Till Cashier' ) );
		$order   = OrderHelper::create_order();
		$order->update_meta_data( '_pos_user', $user_id );
		$order->save();

		$payment = new Payment( $order->get_id() );

		$this->assertStringContainsString( 'Till Cashier', $payment->get_cashier_details_html() );
	}
}
