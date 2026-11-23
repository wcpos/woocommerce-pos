<?php
/**
 * Tests for POS order audit notes on the WooCommerce order edit screen.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Admin\Orders\Single_Order;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WP_UnitTestCase;

/** Test the order-edit audit hooks. */
final class Test_Single_Order extends WP_UnitTestCase {
	use HPOSToggleTrait;

	/**
	 * Whether this test enabled HPOS.
	 *
	 * @var bool
	 */
	private bool $attribution_hpos = false;

	/** Reset posted order fields. */
	protected function tearDown(): void {
		if ( $this->attribution_hpos ) {
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
		unset( $_POST['customer_user'], $_POST['_pos_user'], $_POST['pos_cashier_select_nonce'] );
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
	/**
	 * A completed sale's admin correction records both the actor and original attribution.
	 *
	 * @dataProvider attribution_storage_backends
	 * @param bool $hpos Whether HPOS is authoritative.
	 */
	public function test_cashier_save_attributes_note_to_actor_and_writes_record( bool $hpos ): void {
		// Arrange.
		if ( $hpos ) {
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->setup_cot();
			$this->disable_cot_sync();
			$this->attribution_hpos = true;
		}
		$actor_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
				'display_name' => 'Admin Actor',
			)
		);
		$old_id = self::factory()->user->create( array( 'display_name' => 'Original Cashier' ) );
		$new_id = self::factory()->user->create(
			array(
				'role' => 'cashier',
				'display_name' => 'Corrected Cashier',
			)
		);
		wp_set_current_user( $actor_id );
		$order = OrderHelper::create_order();
		$order->set_status( 'completed' );
		$order->update_meta_data( '_pos_user', (string) $old_id );
		$order->update_meta_data( '_pos_store', '12' );
		$order->save();
		$store = new Fiscal_Record_Store();
		$sale = $store->record(
			array(
				'type' => 'sale',
				'order_id' => $order->get_id(),
				'source_id' => wp_generate_uuid4(),
				'payload' => array(),
			)
		);
		$_POST['pos_cashier_select_nonce'] = wp_create_nonce( 'pos_cashier_select_action' );
		$_POST['_pos_user'] = (string) $new_id;

		// Act.
		( new Single_Order() )->save_cashier_select( $order->get_id() );

		// Assert.
		$this->assertSame( (string) $new_id, wc_get_order( $order->get_id() )->get_meta( '_pos_user' ) );
		$notes = array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $note ) => 0 === strpos( $note->content, 'POS cashier changed' ) ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'POS cashier changed from Original Cashier to Corrected Cashier.', $notes[0]->content );
		$this->assertSame( 'Admin Actor', $notes[0]->added_by );
		$this->assertSame( array(), $this->refused_notes( $order->get_id() ) );
		$records = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'reattribution',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
		$this->assertSame( $actor_id, $records[0]['approver_id'] );
		$this->assertSame( $new_id, $records[0]['cashier_id'] );
		$this->assertSame( 12, $records[0]['store_id'] );
		$this->assertSame(
			array(
				array(
					'field' => 'cashier',
					'from' => $old_id,
					'to' => $new_id,
				),
			),
			$records[0]['payload']['changes']
		);
	}

	/**
	 * A failed fiscal insert must not change the admin cashier or claim it did.
	 *
	 * @dataProvider attribution_storage_backends
	 * @param bool $hpos Whether HPOS is authoritative.
	 */
	public function test_cashier_save_failed_record_leaves_cashier_unchanged( bool $hpos ): void {
		if ( $hpos ) {
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->setup_cot();
			$this->disable_cot_sync();
			$this->attribution_hpos = true;
		}
		$actor_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
				'display_name' => 'Admin Actor',
			)
		);
		$old_id = self::factory()->user->create();
		wp_set_current_user( $actor_id );
		$order = OrderHelper::create_order();
		$order->set_status( 'completed' );
		$order->update_meta_data( '_pos_user', (string) $old_id );
		$order->update_meta_data( '_pos_store', 'store-a' );
		$order->save();
		$store = new Fiscal_Record_Store();
		$store->record(
			array(
				'type' => 'sale',
				'order_id' => $order->get_id(),
				'source_id' => wp_generate_uuid4(),
				'payload' => array(),
			)
		);
		$_POST['pos_cashier_select_nonce'] = wp_create_nonce( 'pos_cashier_select_action' );
		$_POST['_pos_user'] = (string) $actor_id;
		$before_notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'id' );
		$stuck = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_reattribution', $stuck, 10, 2 );
		try {
			( new Single_Order() )->save_cashier_select( $order->get_id() );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_reattribution', $stuck, 10 );
		}

		$this->assertSame( (string) $old_id, wc_get_order( $order->get_id() )->get_meta( '_pos_user' ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$notes = array_filter( $notes, static fn( $note ) => ! in_array( $note->id, $before_notes, true ) );
		$this->assertCount( 1, $notes );
		$note = reset( $notes );
		$this->assertSame( 'POS cashier or store change was not applied because its change record could not be written. Try again.', $note->content );
		$this->assertSame( 'Admin Actor', $note->added_by );
		$this->assertSame(
			array(),
			$store->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'reattribution',
				)
			)
		);
	}

	/**
	 * A completed POS sale with its sale record, posted for a cashier change.
	 *
	 * @param int $old_id Current cashier.
	 * @param int $new_id Posted cashier.
	 */
	private function posted_cashier_change( int $old_id, int $new_id ): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_status( 'completed' );
		$order->update_meta_data( '_pos_user', (string) $old_id );
		$order->update_meta_data( '_pos_store', '12' );
		$order->save();
		( new Fiscal_Record_Store() )->record(
			array(
				'type' => 'sale',
				'order_id' => $order->get_id(),
				'source_id' => wp_generate_uuid4(),
				'payload' => array(),
			)
		);
		$_POST['pos_cashier_select_nonce'] = wp_create_nonce( 'pos_cashier_select_action' );
		$_POST['_pos_user'] = (string) $new_id;

		return $order;
	}

	/**
	 * Reattribution records written for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	private function reattributions( int $order_id ): array {
		return ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order_id,
				'type' => 'reattribution',
			)
		);
	}

	/** A user who may not reassign sales cannot move a sale to another cashier. */
	public function test_save_cashier_select_without_reassign_cap_does_not_change_pos_user(): void {
		// Arrange.
		$actor_id = self::factory()->user->create( array( 'role' => 'cashier' ) );
		$old_id = self::factory()->user->create( array( 'role' => 'cashier' ) );
		$new_id = self::factory()->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $actor_id );
		$order = $this->posted_cashier_change( $old_id, $new_id );

		// Act.
		( new Single_Order() )->save_cashier_select( $order->get_id() );

		// Assert.
		$this->assertSame( (string) $old_id, wc_get_order( $order->get_id() )->get_meta( '_pos_user' ) );
		$this->assertSame( array(), $this->reattributions( $order->get_id() ) );
		$this->assert_refused_note( $order->get_id(), $actor_id, $new_id, $old_id );
	}

	/** A sale cannot be moved to a user without POS access, even by a user who may reassign sales. */
	public function test_save_cashier_select_target_without_pos_access_is_refused(): void {
		// Arrange.
		$actor_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$old_id = self::factory()->user->create( array( 'role' => 'cashier' ) );
		$new_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $actor_id );
		$order = $this->posted_cashier_change( $old_id, $new_id );

		// Act.
		( new Single_Order() )->save_cashier_select( $order->get_id() );

		// Assert.
		$this->assertSame( (string) $old_id, wc_get_order( $order->get_id() )->get_meta( '_pos_user' ) );
		$this->assertSame( array(), $this->reattributions( $order->get_id() ) );
		$this->assert_refused_note( $order->get_id(), $actor_id, $new_id, $old_id );
	}

	/**
	 * Notes on an order that start with the refused-change prefix.
	 *
	 * @param int $order_id Order ID.
	 */
	private function refused_notes( int $order_id ): array {
		return array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order_id ) ), static fn( $note ) => 0 === strpos( $note->content, 'POS cashier change by' ) ) );
	}

	/**
	 * Assert one refused-change note naming the actor, the refused user and the kept cashier.
	 *
	 * @param int $order_id   Order ID.
	 * @param int $actor_id   User who tried the change.
	 * @param int $refused_id Cashier the change was refused for.
	 * @param int $kept_id    Cashier who stays on the order.
	 */
	private function assert_refused_note( int $order_id, int $actor_id, int $refused_id, int $kept_id ): void {
		$notes = $this->refused_notes( $order_id );
		$this->assertCount( 1, $notes );
		$expected = sprintf(
			'POS cashier change by %1$s to %2$s was refused: they may not assign this sale to that user. %3$s remains the cashier.',
			get_userdata( $actor_id )->display_name,
			get_userdata( $refused_id )->display_name,
			get_userdata( $kept_id )->display_name
		);
		$this->assertSame( $expected, $notes[0]->content );
	}

	/** Both supported order storage backends, without HPOS compatibility sync. */
	public function attribution_storage_backends(): array {
		return array(
			'posts' => array( false ),
			'HPOS' => array( true ),
		);
	}
}
