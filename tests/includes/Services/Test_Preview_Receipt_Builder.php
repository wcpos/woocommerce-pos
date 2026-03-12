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
		update_option( 'blogname', 'My Test Store' );
		update_option( 'woocommerce_store_address', '789 Elm Street' );
		update_option( 'woocommerce_store_city', 'Portland' );
		update_option( 'woocommerce_store_postcode', '97201' );
		update_option( 'woocommerce_currency', 'EUR' );

		$data = $this->builder->build();

		$this->assertEquals( 'My Test Store', $data['store']['name'] );
		$this->assertContains( '789 Elm Street', $data['store']['address_lines'] );
		$this->assertEquals( 'EUR', $data['meta']['currency'] );
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

		$data = $this->builder->build();

		$names = array_column( $data['lines'], 'name' );
		$this->assertContains( 'Test Product For Preview', $names );

		$product->delete( true );
	}

	/**
	 * Test that all array sections are populated.
	 *
	 * @covers ::build
	 */
	public function test_all_sections_populated(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$data = $this->builder->build();

		$this->assertNotEmpty( $data['fees'], 'Fees section should not be empty' );
		$this->assertNotEmpty( $data['shipping'], 'Shipping section should not be empty' );
		$this->assertNotEmpty( $data['discounts'], 'Discounts section should not be empty' );
		$this->assertNotEmpty( $data['tax_summary'], 'Tax summary section should not be empty' );
		$this->assertNotEmpty( $data['payments'], 'Payments section should not be empty' );
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
}
