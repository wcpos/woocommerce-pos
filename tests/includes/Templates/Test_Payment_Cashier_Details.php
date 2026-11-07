<?php
/**
 * Tests for the cashier line on the POS pay page.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use ReflectionMethod;
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
	 * Build the template the way get_template() does: the constructor stores the id,
	 * initialize_order_and_nonces() loads the order.
	 *
	 * @param int $order_id Order to render.
	 */
	private function payment_for( int $order_id ): Payment {
		$payment = new Payment( $order_id );
		$init    = new ReflectionMethod( $payment, 'initialize_order_and_nonces' );
		$init->setAccessible( true );
		$init->invoke( $payment );

		return $payment;
	}

	/**
	 * An unpaid order with the given `_pos_user` meta (none when null).
	 *
	 * @param int|null $pos_user Cashier id, or null for no meta.
	 */
	private function unpaid_order( ?int $pos_user ): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_status( 'pending' );
		$order->set_date_paid( null );
		if ( null === $pos_user ) {
			$order->delete_meta_data( '_pos_user' );
		} else {
			$order->update_meta_data( '_pos_user', $pos_user );
		}
		$order->save();

		return $order;
	}

	/**
	 * An order with no `_pos_user` meta renders an empty cashier name, with no PHP warning.
	 *
	 * Orders made outside the POS, and orders whose cashier was deleted, reach the pay page
	 * without a user; `get_user_by()` returns false there and the template used to read
	 * `display_name` off it.
	 */
	public function test_missing_pos_user_renders_empty_cashier_name(): void {
		$order = $this->unpaid_order( null );

		$html = $this->payment_for( $order->get_id() )->get_cashier_details_html();

		$this->assertStringContainsString( 'class="cashier-name"></span>', $html );
	}

	/**
	 * A deleted cashier id renders an empty cashier name too.
	 */
	public function test_deleted_pos_user_renders_empty_cashier_name(): void {
		$order = $this->unpaid_order( 999999 );

		$html = $this->payment_for( $order->get_id() )->get_cashier_details_html();

		$this->assertStringContainsString( 'class="cashier-name"></span>', $html );
	}

	/**
	 * A real cashier is still named.
	 */
	public function test_pos_user_renders_display_name(): void {
		$user_id = $this->factory()->user->create( array( 'display_name' => 'Till Cashier' ) );
		$order   = $this->unpaid_order( $user_id );

		$html = $this->payment_for( $order->get_id() )->get_cashier_details_html();

		$this->assertStringContainsString( 'Till Cashier', $html );
	}
}
