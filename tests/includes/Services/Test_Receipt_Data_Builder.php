<?php
/**
 * Tests for receipt data builder.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Data_Builder class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Data_Builder extends WC_REST_Unit_Test_Case {
	/**
	 * Builder instance.
	 *
	 * @var Receipt_Data_Builder
	 */
	private $builder;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->builder = new Receipt_Data_Builder();
	}

	/**
	 * Create an order with a taxable product for tax-setting assertions.
	 *
	 * @param string $tax_display_mode Tax display mode option.
	 * @param string $prices_include_tax Whether catalog prices include tax.
	 *
	 * @return \WC_Order
	 */
	private function create_taxed_order( string $tax_display_mode = 'itemized', string $prices_include_tax = 'no' ) {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_tax_total_display', $tax_display_mode );
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax );

		TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.000',
				'name'     => 'US Tax',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Taxed Product' );
		$product->set_regular_price( '11.00' );
		$product->set_tax_status( 'taxable' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order->save();

		return $order;
	}

	/**
	 * Test canonical payload includes required top-level keys.
	 */
	public function test_build_includes_required_top_level_keys(): void {
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		foreach ( Receipt_Data_Schema::REQUIRED_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $payload );
		}

		$this->assertEquals( Receipt_Data_Schema::VERSION, $payload['meta']['schema_version'] );
		$this->assertEquals( 'live', $payload['meta']['mode'] );
	}

	/**
	 * Test totals include tax inclusive and exclusive fields.
	 */
	public function test_build_totals_include_inclusive_and_exclusive_fields(): void {
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		foreach ( Receipt_Data_Schema::TOTAL_MONEY_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $payload['totals'] );
			$this->assertIsNumeric( $payload['totals'][ $key ] );
		}
	}

	/**
	 * Test line items include tax inclusive and exclusive values.
	 */
	public function test_build_line_items_include_inclusive_and_exclusive_values(): void {
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		$this->assertNotEmpty( $payload['lines'] );

		$line = $payload['lines'][0];
		$this->assertArrayHasKey( 'unit_price_incl', $line );
		$this->assertArrayHasKey( 'unit_price_excl', $line );
		$this->assertArrayHasKey( 'line_total_incl', $line );
		$this->assertArrayHasKey( 'line_total_excl', $line );
	}

	/**
	 * Test presentation hints follow WooCommerce tax display settings.
	 */
	public function test_build_presentation_hints_reflect_tax_settings(): void {
		$order   = $this->create_taxed_order( 'itemized', 'yes' );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertEquals( 'itemized', $payload['presentation_hints']['display_tax'] );
		$this->assertTrue( $payload['presentation_hints']['prices_entered_with_tax'] );
	}

	/**
	 * Test tax summary reports taxable base values instead of tax-only values.
	 */
	public function test_build_tax_summary_uses_taxable_base_amounts(): void {
		$order   = $this->create_taxed_order( 'single', 'no' );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertNotEmpty( $payload['tax_summary'] );
		$summary = $payload['tax_summary'][0];

		$this->assertArrayHasKey( 'tax_amount', $summary );
		$this->assertArrayHasKey( 'taxable_amount_excl', $summary );
		$this->assertArrayHasKey( 'taxable_amount_incl', $summary );
		$this->assertNotNull( $summary['taxable_amount_excl'] );
		$this->assertGreaterThan( (float) $summary['tax_amount'], (float) $summary['taxable_amount_excl'] );
		$this->assertEqualsWithDelta(
			(float) $summary['taxable_amount_excl'] + (float) $summary['tax_amount'],
			(float) $summary['taxable_amount_incl'],
			0.01
		);
	}

	/**
	 * Test inclusive and exclusive line totals are distinct when tax is enabled.
	 */
	public function test_build_line_totals_reflect_tax_inclusive_and_exclusive_values(): void {
		$order   = $this->create_taxed_order( 'itemized', 'no' );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertNotEmpty( $payload['lines'] );
		$line = $payload['lines'][0];

		$this->assertGreaterThan( (float) $line['line_total_excl'], (float) $line['line_total_incl'] );
		$this->assertGreaterThan( 0, (float) $payload['totals']['tax_total'] );
	}
}
