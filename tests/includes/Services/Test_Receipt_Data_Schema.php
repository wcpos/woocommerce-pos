<?php
/**
 * Tests for Receipt_Data_Schema.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder;
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
		$this->assertContains( 'total_incl', $fields );
		$this->assertContains( 'total_excl', $fields );
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
	 * Test money formatting honors receipt presentation hints.
	 */
	public function test_format_money_fields_uses_presentation_hints(): void {
		$data = array(
			'presentation_hints' => array(
				'currency_position'        => 'right_space',
				'price_thousand_separator' => ' ',
				'price_decimal_separator'  => ',',
				'price_num_decimals'       => 2,
			),
			'totals'             => array(
				'total_incl' => 1234.5,
				'change_total'     => 0,
			),
		);

		$formatted = Receipt_Data_Schema::format_money_fields( $data, 'EUR' );

		$this->assertEquals( '1 234,50 €', $formatted['totals']['total_incl'] );
		$this->assertSame( 0, $formatted['totals']['change_total'] );
	}

	/**
	 * Test get_field_tree returns all required sections.
	 */
	public function test_get_field_tree_returns_all_required_sections(): void {
		$tree = Receipt_Data_Schema::get_field_tree();

		$expected_sections = array( 'receipt', 'receipt.printed', 'order', 'order.created', 'order.paid', 'order.completed', 'store', 'store.tax_ids', 'cashier', 'customer', 'customer.tax_ids', 'lines', 'fees', 'shipping', 'discounts', 'totals', 'tax_summary', 'payments', 'refunds', 'fiscal', 'i18n' );
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

		$array_sections = array( 'store.tax_ids', 'customer.tax_ids', 'lines', 'fees', 'shipping', 'discounts', 'tax_summary', 'payments', 'refunds' );
		foreach ( $array_sections as $section ) {
			$this->assertTrue( $tree[ $section ]['is_array'] ?? false, "{$section} should be marked as array" );
		}

		$scalar_sections = array( 'receipt', 'receipt.printed', 'order', 'order.created', 'order.paid', 'order.completed', 'store', 'cashier', 'customer', 'totals', 'fiscal' );
		foreach ( $scalar_sections as $section ) {
			$this->assertFalse( $tree[ $section ]['is_array'] ?? false, "{$section} should not be marked as array" );
		}
	}

	/**
	 * Test refunds field tree mirrors the builder refund payload shape.
	 */
	public function test_get_field_tree_refunds_include_date_and_typed_lines(): void {
		$tree    = Receipt_Data_Schema::get_field_tree();
		$refunds = $tree['refunds']['fields'];

		$this->assertArrayHasKey( 'date', $refunds );
		$this->assertSame( 'object', $refunds['date']['type'] );

		$this->assertArrayHasKey( 'lines', $refunds );
		$this->assertSame( 'array', $refunds['lines']['type'] );
		$this->assertTrue( $refunds['lines']['is_array'] );
		foreach ( array( 'name', 'sku', 'qty', 'total', 'total_incl', 'total_excl', 'taxes' ) as $field ) {
			$this->assertArrayHasKey( $field, $refunds['lines']['fields'], "refunds.lines missing {$field}" );
		}
		$this->assertSame( 'string', $refunds['lines']['fields']['name']['type'] );
		$this->assertSame( 'string', $refunds['lines']['fields']['sku']['type'] );
		$this->assertSame( 'number', $refunds['lines']['fields']['qty']['type'] );
		$this->assertSame( 'money', $refunds['lines']['fields']['total']['type'] );
		$this->assertSame( 'money', $refunds['lines']['fields']['total_incl']['type'] );
		$this->assertSame( 'money', $refunds['lines']['fields']['total_excl']['type'] );
		$this->assertSame( 'array', $refunds['lines']['fields']['taxes']['type'] );

		$this->assertArrayHasKey( 'fees', $refunds );
		$this->assertTrue( $refunds['fees']['is_array'] );
		foreach ( array( 'label', 'total', 'total_incl', 'total_excl', 'taxes' ) as $field ) {
			$this->assertArrayHasKey( $field, $refunds['fees']['fields'], "refunds.fees missing {$field}" );
		}
		$this->assertSame( 'money', $refunds['fees']['fields']['total_incl']['type'] );

		$this->assertArrayHasKey( 'shipping', $refunds );
		$this->assertTrue( $refunds['shipping']['is_array'] );
		foreach ( array( 'label', 'method_id', 'total', 'total_incl', 'total_excl', 'taxes' ) as $field ) {
			$this->assertArrayHasKey( $field, $refunds['shipping']['fields'], "refunds.shipping missing {$field}" );
		}
		$this->assertSame( 'string', $refunds['shipping']['fields']['method_id']['type'] );
	}

	/**
	 * Test JSON schema declares the new Phase 3 meta fields.
	 */
	public function test_get_json_schema_meta_includes_wc_status_and_created_via(): void {
		$schema      = Receipt_Data_Schema::get_json_schema();
		$meta_props  = $schema['properties']['meta']['properties'];

		$this->assertArrayHasKey( 'wc_status', $meta_props );
		$this->assertSame( 'string', $meta_props['wc_status']['type'] );

		$this->assertArrayHasKey( 'created_via', $meta_props );
		$this->assertSame( 'string', $meta_props['created_via']['type'] );
	}

	/**
	 * Test get_field_tree money fields have money type.
	 */
	public function test_get_field_tree_money_fields_have_money_type(): void {
		$tree = Receipt_Data_Schema::get_field_tree();

		$this->assertSame( 'money', $tree['totals']['fields']['total_incl']['type'] );
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

		$this->assertArrayHasKey( 'opening_hours_vertical', $fields );
		$this->assertSame( 'string', $fields['opening_hours_vertical']['type'] );

		$this->assertArrayHasKey( 'opening_hours_inline', $fields );
		$this->assertSame( 'string', $fields['opening_hours_inline']['type'] );

		$this->assertArrayHasKey( 'opening_hours_notes', $fields );
		$this->assertSame( 'string', $fields['opening_hours_notes']['type'] );

		$this->assertArrayHasKey( 'personal_notes', $fields );
		$this->assertSame( 'string', $fields['personal_notes']['type'] );

		$this->assertArrayHasKey( 'policies_and_conditions', $fields );
		$this->assertSame( 'string', $fields['policies_and_conditions']['type'] );

		$this->assertArrayHasKey( 'footer_imprint', $fields );
		$this->assertSame( 'string', $fields['footer_imprint']['type'] );
	}

	/**
	 * Test get_mock_receipt_data includes new store fields.
	 */
	public function test_get_mock_receipt_data_includes_new_store_fields(): void {
		$data  = Receipt_Data_Schema::get_mock_receipt_data();
		$store = $data['store'];

		$this->assertArrayHasKey( 'opening_hours', $store );
		$this->assertArrayHasKey( 'opening_hours_vertical', $store );
		$this->assertArrayHasKey( 'opening_hours_inline', $store );
		$this->assertArrayHasKey( 'opening_hours_notes', $store );
		$this->assertArrayHasKey( 'tax_ids', $store );
		$this->assertIsString( $store['opening_hours_vertical'] );
		$this->assertIsString( $store['opening_hours_inline'] );
		$this->assertIsString( $store['opening_hours_notes'] );
		$this->assertIsArray( $store['tax_ids'] );
		$this->assertNotEmpty( $store['tax_ids'] );
		$this->assertNotEmpty( $store['opening_hours_vertical'] );
		$this->assertNotEmpty( $store['opening_hours_inline'] );
		$this->assertArrayHasKey( 'type', $store['tax_ids'][0] );
		$this->assertArrayHasKey( 'value', $store['tax_ids'][0] );
		$this->assertArrayHasKey( 'country', $store['tax_ids'][0] );
		$this->assertArrayHasKey( 'label', $store['tax_ids'][0] );
		$this->assertSame( 'us_ein', $store['tax_ids'][0]['type'] );
		$this->assertSame( '12-3456789', $store['tax_ids'][0]['value'] );
	}

	/**
	 * Test field tree exposes practical date format options for each semantic date section.
	 */
	public function test_get_field_tree_exposes_practical_date_format_options(): void {
		$tree = Receipt_Data_Schema::get_field_tree();

		foreach ( array( 'order.created', 'order.paid', 'order.completed', 'receipt.printed' ) as $section ) {
			$fields = $tree[ $section ]['fields'];

			foreach ( array( 'datetime', 'datetime_full', 'date', 'date_long', 'date_ymd', 'date_dmy', 'date_mdy', 'weekday_short', 'weekday_long', 'month_short', 'month_long', 'year' ) as $field ) {
				$this->assertArrayHasKey( $field, $fields, "{$section} missing {$field}" );
				$this->assertSame( 'string', $fields[ $field ]['type'] );
			}
		}
	}

	/**
	 * Test JSON schema exports the current receipt schema version.
	 */
	public function test_get_json_schema_exports_schema_version(): void {
		$schema = Receipt_Data_Schema::get_json_schema();

		$this->assertSame( Receipt_Data_Schema::VERSION, $schema['properties']['meta']['properties']['schema_version']['const'] );
		$this->assertSame( 'https://json-schema.org/draft/2020-12/schema', $schema['$schema'] );
		$this->assertSame( 'ReceiptData', $schema['title'] );
	}

	/**
	 * Test JSON schema requires the canonical top-level receipt keys.
	 */
	public function test_get_json_schema_requires_canonical_top_level_keys(): void {
		$schema = Receipt_Data_Schema::get_json_schema();

		$this->assertEquals( Receipt_Data_Schema::REQUIRED_KEYS, $schema['required'] );

		foreach ( Receipt_Data_Schema::REQUIRED_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $schema['properties'], "Missing schema property: {$key}" );
		}
	}

	/**
	 * Test JSON schema maps field tree types to JSON Schema types.
	 */
	public function test_get_json_schema_maps_field_tree_types(): void {
		$schema = Receipt_Data_Schema::get_json_schema();

		$this->assertSame( 'array', $schema['properties']['lines']['type'] );
		$this->assertSame( 'array', $schema['properties']['refunds']['type'] );
		$this->assertSame( 'object', $schema['properties']['lines']['items']['type'] );
		$this->assertSame( 'array', $schema['properties']['customer']['properties']['tax_ids']['type'] );
		$this->assertSame( 'object', $schema['properties']['customer']['properties']['tax_ids']['items']['type'] );
		$this->assertSame( 'string', $schema['properties']['customer']['properties']['tax_ids']['items']['properties']['value']['type'] );
		$this->assertSame( 'array', $schema['properties']['store']['properties']['tax_ids']['type'] );
		$this->assertSame( 'object', $schema['properties']['store']['properties']['tax_ids']['items']['type'] );
		$this->assertSame( 'string', $schema['properties']['store']['properties']['tax_ids']['items']['properties']['value']['type'] );
		$this->assertSame( 'string', $schema['properties']['store']['properties']['name']['type'] );
		$this->assertEquals( array( 'number', 'string' ), $schema['properties']['totals']['properties']['total']['type'] );
		$this->assertSame( 'object', $schema['properties']['refunds']['items']['properties']['date']['type'] );
		$this->assertSame( 'array', $schema['properties']['refunds']['items']['properties']['lines']['type'] );
		$this->assertSame( 'object', $schema['properties']['refunds']['items']['properties']['lines']['items']['type'] );
		$this->assertSame( 'string', $schema['properties']['refunds']['items']['properties']['lines']['items']['properties']['name']['type'] );
		$this->assertSame( 'string', $schema['properties']['refunds']['items']['properties']['lines']['items']['properties']['sku']['type'] );
		$this->assertSame( 'number', $schema['properties']['refunds']['items']['properties']['lines']['items']['properties']['qty']['type'] );
		$this->assertEquals( array( 'number', 'string' ), $schema['properties']['refunds']['items']['properties']['lines']['items']['properties']['total']['type'] );
		$this->assertSame( 'boolean', $schema['properties']['fiscal']['properties']['is_reprint']['type'] );
	}

	/**
	 * Test preview fixture data satisfies the exported top-level schema contract.
	 */
	public function test_preview_receipt_data_satisfies_exported_schema_contract(): void {
		$schema = Receipt_Data_Schema::get_json_schema();
		$data   = ( new Preview_Receipt_Builder() )->build();

		foreach ( $schema['required'] as $key ) {
			$this->assertArrayHasKey( $key, $data, "Mock data missing required key: {$key}" );
		}

		$this->assertSame( Receipt_Data_Schema::VERSION, $data['meta']['schema_version'] );
		$this->assertIsArray( $data['lines'] );
		$this->assertIsArray( $data['totals'] );
		$this->assertIsArray( $data['payments'] );
		$this->assertIsArray( $data['refunds'] );
	}
}
