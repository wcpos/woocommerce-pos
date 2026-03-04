<?php
/**
 * Tests for Receipt_Data_Schema.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WP_UnitTestCase;

/**
 * Test_Receipt_Data_Schema class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Data_Schema extends WP_UnitTestCase {
	/**
	 * Test MONEY_FIELDS constant contains expected line item fields.
	 */
	public function test_money_fields_contains_line_item_fields(): void {
		$fields = Receipt_Data_Schema::MONEY_FIELDS;

		$this->assertContains( 'unit_price_incl', $fields );
		$this->assertContains( 'unit_price_excl', $fields );
		$this->assertContains( 'line_total_incl', $fields );
		$this->assertContains( 'line_total_excl', $fields );
		$this->assertContains( 'line_subtotal_incl', $fields );
		$this->assertContains( 'line_subtotal_excl', $fields );
		$this->assertContains( 'discounts_incl', $fields );
		$this->assertContains( 'discounts_excl', $fields );
	}

	/**
	 * Test MONEY_FIELDS constant contains totals fields.
	 */
	public function test_money_fields_contains_totals_fields(): void {
		$fields = Receipt_Data_Schema::MONEY_FIELDS;

		$this->assertContains( 'subtotal_incl', $fields );
		$this->assertContains( 'subtotal_excl', $fields );
		$this->assertContains( 'grand_total_incl', $fields );
		$this->assertContains( 'grand_total_excl', $fields );
		$this->assertContains( 'tax_total', $fields );
		$this->assertContains( 'paid_total', $fields );
		$this->assertContains( 'change_total', $fields );
	}

	/**
	 * Test MONEY_FIELDS constant contains payment and tax fields.
	 */
	public function test_money_fields_contains_payment_and_tax_fields(): void {
		$fields = Receipt_Data_Schema::MONEY_FIELDS;

		$this->assertContains( 'amount', $fields );
		$this->assertContains( 'tendered', $fields );
		$this->assertContains( 'change', $fields );
		$this->assertContains( 'tax_amount', $fields );
		$this->assertContains( 'taxable_amount_excl', $fields );
		$this->assertContains( 'taxable_amount_incl', $fields );
		$this->assertContains( 'total_incl', $fields );
		$this->assertContains( 'total_excl', $fields );
	}
}
