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

	/**
	 * Test get_field_tree returns all required sections.
	 */
	public function test_get_field_tree_returns_all_required_sections(): void {
		$tree = Receipt_Data_Schema::get_field_tree();

		$expected_sections = array( 'meta', 'store', 'cashier', 'customer', 'lines', 'fees', 'shipping', 'discounts', 'totals', 'tax_summary', 'payments', 'fiscal' );
		foreach ( $expected_sections as $section ) {
			$this->assertArrayHasKey( $section, $tree, "Missing section: {$section}" );
			$this->assertArrayHasKey( 'label', $tree[ $section ] );
			$this->assertArrayHasKey( 'fields', $tree[ $section ] );
		}
	}

	/**
	 * Test get_field_tree marks array sections correctly.
	 */
	public function test_get_field_tree_marks_array_sections(): void {
		$tree = Receipt_Data_Schema::get_field_tree();

		$array_sections = array( 'lines', 'fees', 'shipping', 'discounts', 'tax_summary', 'payments' );
		foreach ( $array_sections as $section ) {
			$this->assertTrue( $tree[ $section ]['is_array'] ?? false, "{$section} should be marked as array" );
		}

		$scalar_sections = array( 'meta', 'store', 'cashier', 'customer', 'totals', 'fiscal' );
		foreach ( $scalar_sections as $section ) {
			$this->assertFalse( $tree[ $section ]['is_array'] ?? false, "{$section} should not be marked as array" );
		}
	}

	/**
	 * Test get_field_tree money fields have money type.
	 */
	public function test_get_field_tree_money_fields_have_money_type(): void {
		$tree = Receipt_Data_Schema::get_field_tree();

		$this->assertSame( 'money', $tree['totals']['fields']['grand_total_incl']['type'] );
		$this->assertSame( 'money', $tree['lines']['fields']['line_total_incl']['type'] );
		$this->assertSame( 'string', $tree['store']['fields']['name']['type'] );
	}

	/**
	 * Test get_field_tree excludes presentation_hints.
	 */
	public function test_get_field_tree_excludes_presentation_hints(): void {
		$tree = Receipt_Data_Schema::get_field_tree();
		$this->assertArrayNotHasKey( 'presentation_hints', $tree );
	}

	/**
	 * Test get_field_tree fiscal section includes new fiscal fields.
	 */
	public function test_get_field_tree_fiscal_includes_new_fields(): void {
		$tree   = Receipt_Data_Schema::get_field_tree();
		$fields = $tree['fiscal']['fields'];

		// Existing fields still present.
		$this->assertArrayHasKey( 'immutable_id', $fields );
		$this->assertArrayHasKey( 'qr_payload', $fields );

		// New fields.
		$this->assertArrayHasKey( 'signature_excerpt', $fields );
		$this->assertSame( 'string', $fields['signature_excerpt']['type'] );

		$this->assertArrayHasKey( 'document_label', $fields );
		$this->assertSame( 'string', $fields['document_label']['type'] );

		$this->assertArrayHasKey( 'is_reprint', $fields );
		$this->assertSame( 'boolean', $fields['is_reprint']['type'] );

		$this->assertArrayHasKey( 'reprint_count', $fields );
		$this->assertSame( 'number', $fields['reprint_count']['type'] );

		$this->assertArrayHasKey( 'extra_fields', $fields );
		$this->assertSame( 'array', $fields['extra_fields']['type'] );
	}

	/**
	 * Test get_field_tree store section includes new store fields.
	 */
	public function test_get_field_tree_store_includes_new_fields(): void {
		$tree   = Receipt_Data_Schema::get_field_tree();
		$fields = $tree['store']['fields'];

		$this->assertArrayHasKey( 'logo', $fields );
		$this->assertSame( 'string', $fields['logo']['type'] );

		$this->assertArrayHasKey( 'opening_hours', $fields );
		$this->assertSame( 'string', $fields['opening_hours']['type'] );

		$this->assertArrayHasKey( 'personal_notes', $fields );
		$this->assertSame( 'string', $fields['personal_notes']['type'] );

		$this->assertArrayHasKey( 'policies_and_conditions', $fields );
		$this->assertSame( 'string', $fields['policies_and_conditions']['type'] );

		$this->assertArrayHasKey( 'footer_imprint', $fields );
		$this->assertSame( 'string', $fields['footer_imprint']['type'] );
	}
}
