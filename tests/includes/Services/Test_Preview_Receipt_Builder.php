<?php
/**
 * Tests for preview receipt data builder.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WP_UnitTestCase;

/**
 * Test_Preview_Receipt_Builder class.
 *
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder
 */
class Test_Preview_Receipt_Builder extends WP_UnitTestCase {

	/**
	 * Builder instance.
	 *
	 * @var Preview_Receipt_Builder
	 */
	private Preview_Receipt_Builder $builder;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->builder = new Preview_Receipt_Builder();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that build() returns all required top-level keys.
	 *
	 * @covers ::build
	 */
	public function test_build_returns_all_required_keys(): void {
		$data = $this->builder->build();

		foreach ( Receipt_Data_Schema::REQUIRED_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $data, "Missing required key: {$key}" );
		}
	}

	/**
	 * Test that store info uses WooCommerce settings.
	 *
	 * @covers ::build
	 */
	public function test_store_info_uses_wc_settings(): void {
		$original_name     = get_option( 'blogname' );
		$original_address  = get_option( 'woocommerce_store_address' );
		$original_city     = get_option( 'woocommerce_store_city' );
		$original_postcode = get_option( 'woocommerce_store_postcode' );
		$original_currency = get_option( 'woocommerce_currency' );

		try {
			update_option( 'blogname', 'My Test Store' );
			update_option( 'woocommerce_store_address', '789 Elm Street' );
			update_option( 'woocommerce_store_city', 'Portland' );
			update_option( 'woocommerce_store_postcode', '97201' );
			update_option( 'woocommerce_currency', 'EUR' );

			$data = $this->builder->build();

			$this->assertEquals( 'My Test Store', $data['store']['name'] );
			$this->assertContains( '789 Elm Street', $data['store']['address_lines'] );
			$this->assertEquals( 'EUR', $data['meta']['currency'] );
		} finally {
			update_option( 'blogname', $original_name );
			update_option( 'woocommerce_store_address', $original_address );
			update_option( 'woocommerce_store_city', $original_city );
			update_option( 'woocommerce_store_postcode', $original_postcode );
			update_option( 'woocommerce_currency', $original_currency );
		}
	}

	/**
	 * Test that at least 2 line items are generated.
	 *
	 * @covers ::build
	 */
	public function test_lines_are_populated(): void {
		$data = $this->builder->build();

		$this->assertGreaterThanOrEqual( 2, count( $data['lines'] ) );

		foreach ( $data['lines'] as $line ) {
			$this->assertArrayHasKey( 'name', $line );
			$this->assertArrayHasKey( 'qty', $line );
			$this->assertArrayHasKey( 'unit_price_incl', $line );
			$this->assertArrayHasKey( 'line_total_incl', $line );
			$this->assertNotEmpty( $line['name'] );
			$this->assertGreaterThan( 0, $line['qty'] );
		}
	}

	/**
	 * Test that real catalog products are used when available.
	 *
	 * @covers ::build
	 */
	public function test_uses_real_products_when_available(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product For Preview' );
		$product->set_regular_price( '25.00' );
		$product->set_price( 25.00 );
		$product->set_status( 'publish' );
		$product->save();

		try {
			$data = $this->builder->build();

			$names = array_column( $data['lines'], 'name' );
			$this->assertContains( 'Test Product For Preview', $names );
		} finally {
			$product->delete( true );
		}
	}

	/**
	 * Test that all array sections are populated.
	 *
	 * @covers ::build
	 */
	public function test_all_sections_populated(): void {
		$original_calc_taxes = get_option( 'woocommerce_calc_taxes' );

		try {
			update_option( 'woocommerce_calc_taxes', 'yes' );

			$data = $this->builder->build();

			$this->assertNotEmpty( $data['fees'], 'Fees section should not be empty' );
			$this->assertNotEmpty( $data['shipping'], 'Shipping section should not be empty' );
			$this->assertNotEmpty( $data['discounts'], 'Discounts section should not be empty' );
			$this->assertNotEmpty( $data['tax_summary'], 'Tax summary section should not be empty' );
			$this->assertNotEmpty( $data['payments'], 'Payments section should not be empty' );
		} finally {
			update_option( 'woocommerce_calc_taxes', $original_calc_taxes );
		}
	}

	/**
	 * Test that totals are internally consistent.
	 *
	 * @covers ::build
	 */
	public function test_totals_are_consistent(): void {
		$data   = $this->builder->build();
		$totals = $data['totals'];

		$this->assertEqualsWithDelta(
			$totals['grand_total_excl'] + $totals['tax_total'],
			$totals['grand_total_incl'],
			0.02,
			'grand_total_incl should equal grand_total_excl + tax_total'
		);

		$this->assertEquals(
			$totals['grand_total_incl'],
			$totals['paid_total'],
			'paid_total should equal grand_total_incl'
		);
	}

	/**
	 * Test that customer has filled address data.
	 *
	 * @covers ::build
	 */
	public function test_customer_has_filled_address(): void {
		$data = $this->builder->build();

		$this->assertNotEmpty( $data['customer']['name'] );
		$this->assertNotEmpty( $data['customer']['billing_address']['address_1'] );
	}

	/**
	 * Test that cashier uses the current logged-in user.
	 *
	 * @covers ::build
	 */
	public function test_cashier_uses_current_user(): void {
		$user_id = $this->factory()->user->create(
			array(
				'display_name' => 'Test Cashier Person',
				'role'         => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$data = $this->builder->build();

		$this->assertEquals( 'Test Cashier Person', $data['cashier']['name'] );
		$this->assertEquals( $user_id, $data['cashier']['id'] );
	}

	/**
	 * Test that payment includes tendered and change amounts.
	 *
	 * @covers ::build
	 */
	public function test_payments_include_tendered_and_change(): void {
		$data    = $this->builder->build();
		$payment = $data['payments'][0];

		$this->assertGreaterThanOrEqual( $payment['amount'], $payment['tendered'], 'Tendered should be at least the payment amount' );
		$this->assertEqualsWithDelta( $payment['tendered'] - $payment['amount'], $payment['change'], 0.01, 'Change should equal tendered minus amount' );
	}

	/**
	 * Test that customer address uses store country/city.
	 *
	 * @covers ::build
	 */
	public function test_customer_uses_store_location(): void {
		$original_country  = get_option( 'woocommerce_default_country' );
		$original_city     = get_option( 'woocommerce_store_city' );
		$original_postcode = get_option( 'woocommerce_store_postcode' );

		try {
			update_option( 'woocommerce_default_country', 'DE:BE' );
			update_option( 'woocommerce_store_city', 'Berlin' );
			update_option( 'woocommerce_store_postcode', '10115' );

			$data    = $this->builder->build();
			$billing = $data['customer']['billing_address'];

			$this->assertEquals( 'DE', $billing['country'] );
			$this->assertEquals( 'Berlin', $billing['city'] );
			$this->assertEquals( '10115', $billing['postcode'] );
			// German sample customer name.
			$this->assertEquals( 'Anna', $billing['first_name'] );
			$this->assertEquals( 'Mueller', $billing['last_name'] );
		} finally {
			update_option( 'woocommerce_default_country', $original_country );
			update_option( 'woocommerce_store_city', $original_city );
			update_option( 'woocommerce_store_postcode', $original_postcode );
		}
	}

	/**
	 * Test that Pro fields get placeholder values when not configured.
	 *
	 * @covers ::build
	 */
	public function test_pro_fields_have_placeholders_when_empty(): void {
		// Without Pro, these fields are normally empty/null.
		$data  = $this->builder->build();
		$store = $data['store'];

		// All Pro fields should be non-null strings in preview.
		$this->assertIsString( $store['opening_hours'] );
		$this->assertIsString( $store['personal_notes'] );
		$this->assertIsString( $store['policies_and_conditions'] );
		$this->assertIsString( $store['footer_imprint'] );

		$this->assertNotEmpty( $store['opening_hours'] );
		$this->assertNotEmpty( $store['personal_notes'] );
		$this->assertNotEmpty( $store['policies_and_conditions'] );
		$this->assertNotEmpty( $store['footer_imprint'] );
	}

	/**
	 * Test that line-item discounts are populated proportionally.
	 *
	 * @covers ::build
	 */
	public function test_line_items_have_proportional_discounts(): void {
		$data = $this->builder->build();

		$total_line_discounts_excl = 0.0;
		foreach ( $data['lines'] as $line ) {
			$this->assertArrayHasKey( 'discounts_excl', $line );
			$this->assertGreaterThan( 0.0, $line['discounts_excl'], 'Each line should have a non-zero discount' );

			// line_total should equal line_subtotal minus discount.
			$this->assertEqualsWithDelta(
				$line['line_subtotal_excl'] - $line['discounts_excl'],
				$line['line_total_excl'],
				0.02,
				'line_total_excl should be line_subtotal_excl - discounts_excl'
			);

			$total_line_discounts_excl += $line['discounts_excl'];
		}

		// Sum of per-line discounts should match order-level discount.
		$this->assertEqualsWithDelta(
			$data['totals']['discount_total_excl'],
			$total_line_discounts_excl,
			0.02,
			'Sum of line discounts should match order discount total'
		);
	}

	/**
	 * Test that unknown country falls back to US sample customer.
	 *
	 * @covers ::build
	 */
	public function test_customer_falls_back_to_us_for_unknown_country(): void {
		$original_country = get_option( 'woocommerce_default_country' );

		try {
			update_option( 'woocommerce_default_country', 'ZZ' );

			$data    = $this->builder->build();
			$billing = $data['customer']['billing_address'];

			$this->assertEquals( 'ZZ', $billing['country'] );
			$this->assertEquals( 'Sarah', $billing['first_name'] );
		} finally {
			update_option( 'woocommerce_default_country', $original_country );
		}
	}

	/**
	 * Test that build() returns fiscal section with all expected fields.
	 *
	 * @covers ::build
	 */
	public function test_build_returns_fiscal_with_all_fields(): void {
		$data   = $this->builder->build();
		$fiscal = $data['fiscal'];

		// Existing fields.
		$this->assertArrayHasKey( 'immutable_id', $fiscal );
		$this->assertArrayHasKey( 'receipt_number', $fiscal );
		$this->assertArrayHasKey( 'sequence', $fiscal );
		$this->assertArrayHasKey( 'hash', $fiscal );
		$this->assertArrayHasKey( 'qr_payload', $fiscal );
		$this->assertArrayHasKey( 'tax_agency_code', $fiscal );
		$this->assertArrayHasKey( 'signed_at', $fiscal );

		// New fields.
		$this->assertArrayHasKey( 'signature_excerpt', $fiscal );
		$this->assertArrayHasKey( 'document_label', $fiscal );
		$this->assertArrayHasKey( 'is_reprint', $fiscal );
		$this->assertArrayHasKey( 'reprint_count', $fiscal );
		$this->assertArrayHasKey( 'extra_fields', $fiscal );

		// Preview should have sample data (non-empty).
		$this->assertNotEmpty( $fiscal['signature_excerpt'] );
		$this->assertNotEmpty( $fiscal['document_label'] );
		$this->assertNotEmpty( $fiscal['qr_payload'] );
		$this->assertNotEmpty( $fiscal['receipt_number'] );
		$this->assertIsArray( $fiscal['extra_fields'] );
		$this->assertNotEmpty( $fiscal['extra_fields'] );
		$this->assertFalse( $fiscal['is_reprint'] );
		$this->assertSame( 0, $fiscal['reprint_count'] );
	}

	/**
	 * Test that fiscal extra_fields have label and value keys.
	 *
	 * @covers ::build
	 */
	public function test_fiscal_extra_fields_have_label_and_value(): void {
		$data         = $this->builder->build();
		$extra_fields = $data['fiscal']['extra_fields'];

		foreach ( $extra_fields as $field ) {
			$this->assertArrayHasKey( 'label', $field );
			$this->assertArrayHasKey( 'value', $field );
			$this->assertIsString( $field['label'] );
			$this->assertIsString( $field['value'] );
		}
	}
}
