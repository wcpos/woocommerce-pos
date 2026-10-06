<?php
/**
 * Tests for POS order audit notes on the WooCommerce order edit screen.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Admin\Orders\Single_Order;
use WP_UnitTestCase;

/** Test the order-edit audit hooks. */
final class Test_Single_Order extends WP_UnitTestCase {
	/** Reset posted order fields. */
	protected function tearDown(): void {
		unset( $_POST['customer_user'] );
		parent::tearDown();
	}

	/**
	 * Return order-note contents.
	 *
	 * @param int $order_id Order ID.
	 */
	private function note_contents( int $order_id ): array {
		return array_map(
			static fn( $note ) => $note->content,
			wc_get_order_notes( array( 'order_id' => $order_id ) )
		);
	}

	/** Test the callback runs before WooCommerce's priority-40 save. */
	public function test_customer_change_note_is_hooked_before_core_save(): void {
		$handler = new Single_Order();

		$this->assertSame( 10, has_action( 'woocommerce_process_shop_order_meta', array( $handler, 'add_customer_change_note' ) ) );
	}

	/** Test each POS tender is displayed under the order totals. */
	public function test_render_pos_payments_lists_each_tender_for_pos_order(): void {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->update_meta_data( '_woocommerce_pos_payments', '[{"method":"pos_card","title":"Card","amount":"20.00","reference":"auth-1"},{"method":"pos_cash","title":"Cash","amount":"19.00","tendered":"20.00","change":"1.00"}]' );
		$order->save();

		ob_start();
		( new Single_Order() )->render_pos_payments( $order->get_id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'POS payments', $output );
		$this->assertStringContainsString( 'Card (auth-1):', $output );
		$this->assertStringContainsString( 'Cash:', $output );
		$this->assertStringContainsString( 'Amount Tendered:', $output );
		$this->assertStringContainsString( 'Change:', $output );
		$this->assertStringContainsString( wc_price( 20, array( 'currency' => $order->get_currency() ) ), $output );
	}

	/** Test orders without a POS payments list produce no payment rows. */
	public function test_render_pos_payments_prints_nothing_without_list_or_for_web_order(): void {
		$pos_order = OrderHelper::create_order();
		$pos_order->set_created_via( 'woocommerce-pos' );
		$pos_order->save();
		$web_order = OrderHelper::create_order();
		$web_order->set_created_via( 'checkout' );
		$web_order->update_meta_data( '_woocommerce_pos_payments', '[{"method":"pos_card","title":"Card","amount":"20.00","reference":"auth-1"},{"method":"pos_cash","title":"Cash","amount":"19.00","tendered":"20.00","change":"1.00"}]' );
		$web_order->save();
		$handler = new Single_Order();

		ob_start();
		$handler->render_pos_payments( $pos_order->get_id() );
		$pos_output = ob_get_clean();
		ob_start();
		$handler->render_pos_payments( $web_order->get_id() );
		$web_output = ob_get_clean();

		$this->assertSame( '', $pos_output );
		$this->assertSame( '', $web_output );
	}

	/** Test the payments callback is registered after admin order totals. */
	public function test_render_pos_payments_is_hooked_after_admin_totals(): void {
		$handler = new Single_Order();

		$this->assertSame( 10, has_action( 'woocommerce_admin_order_totals_after_total', array( $handler, 'render_pos_payments' ) ) );
	}

	/** Test customer changes are noted only for POS orders. */
	public function test_customer_change_adds_note_only_for_pos_order(): void {
		$old_customer = self::factory()->user->create( array( 'display_name' => 'Old Admin Customer' ) );
		$new_customer = self::factory()->user->create( array( 'display_name' => 'New Admin Customer' ) );
		$pos_order = OrderHelper::create_order();
		$pos_order->set_created_via( 'woocommerce-pos' );
		$pos_order->set_customer_id( $old_customer );
		$pos_order->save();
		$web_order = OrderHelper::create_order();
		$web_order->set_customer_id( $old_customer );
		$web_order->save();
		$_POST['customer_user'] = (string) $new_customer;
		$handler = new Single_Order();

		$handler->add_customer_change_note( $pos_order->get_id() );
		$handler->add_customer_change_note( $web_order->get_id() );

		$this->assertContains(
			'Customer changed from Old Admin Customer to New Admin Customer.',
			$this->note_contents( $pos_order->get_id() )
		);
		$this->assertSame( array(), $this->note_contents( $web_order->get_id() ) );
	}
}
