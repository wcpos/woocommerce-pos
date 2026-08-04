<?php
/**
 * Tests for order-note display-name resolution.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Order_Notes;
use WCPOS\WooCommercePOS\Services\Store_Defaults;
use WP_UnitTestCase;

/** Test order-note label resolution. */
final class Test_Order_Notes extends WP_UnitTestCase {
	/** Test user display names and missing-user fallbacks. */
	public function test_cashier_and_customer_names_use_users_and_fallbacks(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Resolved User' ) );

		$this->assertSame( 'Resolved User', Order_Notes::cashier_name( $user_id ) );
		$this->assertSame( 'Unknown', Order_Notes::cashier_name( 999999 ) );
		$this->assertSame( 'Guest', Order_Notes::customer_name( 0 ) );
		$this->assertSame( 'Resolved User', Order_Notes::customer_name( $user_id ) );
		$this->assertSame( '#999999', Order_Notes::customer_name( 999999 ) );
	}

	/** Test free, missing, Pro-post, and string store identifiers. */
	public function test_store_name_resolves_default_missing_and_store_post(): void {
		$store_id = self::factory()->post->create(
			array(
				'post_type'  => 'wcpos_store',
				'post_title' => 'Named Store',
			)
		);

		$this->assertSame( Store_Defaults::name(), Order_Notes::store_name( 0 ) );
		$this->assertSame( 'Store #999999', Order_Notes::store_name( 999999 ) );
		$this->assertSame( 'Named Store', Order_Notes::store_name( $store_id ) );
		$this->assertSame( 'store-uuid', Order_Notes::store_name( 'store-uuid' ) );
	}

	/** Test cash amounts are formatted in the order currency, not the site default. */
	public function test_cash_note_formats_amounts_in_the_order_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );

		$order = OrderHelper::create_order();
		$order->set_currency( 'EUR' );
		$order->save();

		Order_Notes::add_cash_note( $order, '50.00', '10.00' );

		$note = $this->find_note( $order->get_id(), 'Cash payment received' );

		$this->assertStringContainsString( get_woocommerce_currency_symbol( 'EUR' ), $note->content );
		$this->assertStringNotContainsString( get_woocommerce_currency_symbol( 'USD' ), $note->content );
	}

	/** Test audit notes are internal and attributed to the acting user. */
	public function test_notes_are_internal_and_attributed_to_the_acting_user(): void {
		$actor_id = self::factory()->user->create(
			array(
				'display_name' => 'Note Actor',
				'role'         => 'administrator',
			)
		);
		wp_set_current_user( $actor_id );

		$order = OrderHelper::create_order();
		Order_Notes::add_creation_note( $order, $actor_id, 0 );

		$note = $this->find_note( $order->get_id(), 'Order created via POS' );

		$this->assertFalse( $note->customer_note );
		$this->assertSame( 'Note Actor', $note->added_by );
	}

	/**
	 * Return the single order note whose content starts with the given prefix.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $prefix   Note content prefix.
	 */
	private function find_note( int $order_id, string $prefix ): object {
		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order_id ) ),
				static fn( $note ) => 0 === strpos( wp_strip_all_tags( $note->content ), $prefix )
			)
		);

		$this->assertCount( 1, $notes, sprintf( 'Expected exactly one "%s" note.', $prefix ) );

		return $notes[0];
	}
}
