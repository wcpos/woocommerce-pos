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
