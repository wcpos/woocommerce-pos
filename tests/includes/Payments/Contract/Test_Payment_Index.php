<?php
/**
 * Payment_Index lookups match the index key byte-exactly.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

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
}
