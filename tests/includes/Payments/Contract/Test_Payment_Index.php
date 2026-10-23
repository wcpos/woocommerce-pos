<?php
/**
 * Payment_Index lookups match the index key byte-exactly.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Payment_Index;
use WC_Unit_Test_Case;

/**
 * The meta tables compare `meta_key` under a case-insensitive, trailing-space-padded collation;
 * PHP and WooCommerce do not. A row under a variant spelling is not an index row.
 */
class Test_Payment_Index extends WC_Unit_Test_Case {
	/** Spellings the `_ci` PAD SPACE collation matches to `_wcpos_payment_id`. */
	public static function index_key_variants(): array {
		return array(
			'upper case'     => array( '_WCPOS_PAYMENT_ID' ),
			'trailing space' => array( '_wcpos_payment_id ' ),
		);
	}

	/**
	 * A variant spelling of the payment-id key does not make an order the payment's holder.
	 *
	 * @dataProvider index_key_variants
	 *
	 * @param string $key Variant key.
	 */
	public function test_order_id_for_payment_a_case_variant_index_row_is_not_found( string $key ): void {
		// Arrange.
		$payment_id = wp_generate_uuid4();
		$order      = wc_create_order();
		$order->add_meta_data( $key, $payment_id );
		$order->save();

		// Act.
		$found = Payment_Index::order_id_for_payment( $payment_id );

		// Assert.
		$this->assertSame( 0, $found );
	}

	/** The exact key still finds its order. */
	public function test_order_id_for_payment_the_exact_index_row_is_found(): void {
		// Arrange.
		$payment_id = wp_generate_uuid4();
		$order      = wc_create_order();
		$order->add_meta_data( '_wcpos_payment_id', $payment_id );
		$order->save();

		// Act.
		$found = Payment_Index::order_id_for_payment( $payment_id );

		// Assert.
		$this->assertSame( $order->get_id(), $found );
	}

	/** The sweep reaches live orders holding the live-leg index, least recently modified first. */
	public function test_order_ids_with_live_legs_live_orders_come_oldest_modified_first(): void {
		// Arrange.
		$newest = $this->live_leg_order( time() - 60 );
		$oldest = $this->live_leg_order( time() - 3600 );
		$middle = $this->live_leg_order( time() - 600 );
		$this->live_leg_order( time() - 7200, 'trash' );
		$this->live_leg_order( time() - 7200, 'auto-draft' );
		$settled = wc_create_order();

		// Act.
		$ids = Payment_Index::order_ids_with_live_legs( 10 );

		// Assert.
		$this->assertSame( array( $oldest, $middle, $newest ), $ids );
		$this->assertNotContains( $settled->get_id(), $ids );
	}

	/** The batch cap keeps the oldest; a cap below one selects nothing. */
	public function test_order_ids_with_live_legs_the_cap_keeps_the_oldest(): void {
		// Arrange.
		$this->live_leg_order( time() - 60 );
		$oldest = $this->live_leg_order( time() - 3600 );

		// Act.
		$one  = Payment_Index::order_ids_with_live_legs( 1 );
		$none = Payment_Index::order_ids_with_live_legs( 0 );

		// Assert.
		$this->assertSame( array( $oldest ), $one );
		$this->assertSame( array(), $none );
	}

	/**
	 * An order holding the live-leg index, its modified time and status written straight to storage.
	 *
	 * @param int    $modified Modified timestamp.
	 * @param string $status   Stored status.
	 */
	private function live_leg_order( int $modified, string $status = 'wc-pending' ): int {
		global $wpdb;
		$order = wc_create_order();
		$order->add_meta_data( Ledger::LIVE_LEG_META_KEY, '1' );
		$order->save();
		$stamp = gmdate( 'Y-m-d H:i:s', $modified );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update(
				$wpdb->prefix . 'wc_orders',
				array(
					'date_updated_gmt' => $stamp,
					'status' => $status,
				),
				array( 'id' => $order->get_id() )
			);
		} else {
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified_gmt' => $stamp,
					'post_status' => $status,
				),
				array( 'ID' => $order->get_id() )
			);
			clean_post_cache( $order->get_id() );
		}
		return $order->get_id();
	}
}
