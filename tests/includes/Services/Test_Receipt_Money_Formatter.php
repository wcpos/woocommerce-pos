<?php
/**
 * Tests for Receipt_Money_Formatter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Money_Formatter;
use WP_UnitTestCase;

/**
 * Test_Receipt_Money_Formatter class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Money_Formatter extends WP_UnitTestCase {
	/**
	 * Test money formatting adds _display variants alongside the numeric values.
	 *
	 * `format_money_fields` does NOT replace numbers in-place — it KEEPS the
	 * numeric value at the bare key and ADDS a `<key>_display` companion with
	 * the locale-formatted currency string. This shape mirrors the JS
	 * `formatReceiptData` exactly so a single Mustache template renders the
	 * same way in both pipelines.
	 *
	 * If this assertion ever has to be "fixed" by switching back to in-place
	 * replacement, please don't — cross-pipeline templates depend on the
	 * `_display` companion. See the comment block in
	 * `Receipt_Data_Schema::format_money_fields()`.
	 */
	public function test_format_uses_presentation_hints(): void {
		$data = array(
			'presentation_hints' => array(
				'currency_position'        => 'right_space',
				'price_thousand_separator' => ' ',
				'price_decimal_separator'  => ',',
				'price_num_decimals'       => 2,
			),
			'totals'             => array(
				'total_incl'   => 1234.5,
				'change_total' => 0,
			),
		);

		$formatted = Receipt_Money_Formatter::format( $data, 'EUR' );

		// Bare key remains numeric.
		$this->assertSame( 1234.5, $formatted['totals']['total_incl'] );
		// _display companion holds the formatted currency string.
		$this->assertEquals( '1 234,50 €', $formatted['totals']['total_incl_display'] );
		// Zero on a "zero-falsy" field stays numeric 0 so Mustache section
		// guards (`{{#change_total}}…{{/change_total}}`) keep treating it as
		// falsy; the _display companion is an empty string for the same case.
		$this->assertSame( 0, $formatted['totals']['change_total'] );
		$this->assertSame( '', $formatted['totals']['change_total_display'] );
	}

	/**
	 * Savings values keep numeric bare keys and zero-falsy display companions.
	 */
	public function test_format_formats_savings_and_preserves_unknown_nulls(): void {
		$data = array(
			'lines' => array(
				array(
					'regular_price' => 20,
					'line_savings'  => 10,
				),
				array(
					'regular_price' => null,
					'line_savings'  => 0,
				),
			),
		);

		$formatted = Receipt_Money_Formatter::format( $data, 'USD' );

		$this->assertSame( 20.0, $formatted['lines'][0]['regular_price'] );
		$this->assertNotEmpty( $formatted['lines'][0]['regular_price_display'] );
		$this->assertSame( 10.0, $formatted['lines'][0]['line_savings'] );
		$this->assertNotEmpty( $formatted['lines'][0]['line_savings_display'] );
		$this->assertNull( $formatted['lines'][1]['regular_price'] );
		$this->assertArrayNotHasKey( 'regular_price_display', $formatted['lines'][1] );
		$this->assertSame( 0, $formatted['lines'][1]['line_savings'] );
		$this->assertSame( '', $formatted['lines'][1]['line_savings_display'] );
	}

	/**
	 * Lock the v1 _display contract: bare money keys stay numeric across the
	 * full receipt tree.
	 *
	 * Anti-revert assertion. If a future review feedback round suggests
	 * "format money fields in-place so legacy templates keep rendering",
	 * the suggestion is wrong — gallery templates use `_display` and rely
	 * on bare keys being numeric for math (`{{#change_total}}` guards,
	 * fee/shipping aggregation, etc.).
	 */
	public function test_format_keeps_bare_keys_numeric(): void {
		$data = array(
			'totals' => array(
				'total_incl'      => 99.99,
				'subtotal_incl'   => 90.00,
				'tax_total'       => 9.99,
				'change_total'    => 0,
			),
			'lines'  => array(
				array(
					'name'           => 'Widget',
					'qty'            => 1,
					'unit_price'     => 99.99,
					'line_total'     => 99.99,
					'line_total_incl' => 99.99,
				),
			),
			'payments' => array(
				array(
					'method_title' => 'Cash',
					'amount'       => 100.00,
					'tendered'     => 100.00,
					'change'       => 0.01,
				),
			),
		);

		$formatted = Receipt_Money_Formatter::format( $data, 'USD' );

		// Bare keys are numeric throughout.
		$this->assertIsFloat( $formatted['totals']['total_incl'] );
		$this->assertIsFloat( $formatted['lines'][0]['line_total_incl'] );
		$this->assertIsFloat( $formatted['payments'][0]['amount'] );

		// _display companions are non-empty currency strings (except zero-falsy).
		$this->assertNotEmpty( $formatted['totals']['total_incl_display'] );
		$this->assertNotEmpty( $formatted['lines'][0]['line_total_incl_display'] );
		$this->assertNotEmpty( $formatted['payments'][0]['amount_display'] );
	}

	/**
	 * Formatting an already formatted sample preserves its output.
	 */
	public function test_format_is_idempotent_on_its_own_output(): void {
		$data     = ( new Preview_Receipt_Builder() )->build();
		$currency = $data['order']['currency'];

		$once  = Receipt_Money_Formatter::format( $data, $currency );
		$twice = Receipt_Money_Formatter::format( $once, $currency );

		$this->assertSame( $once, $twice );
	}
}
