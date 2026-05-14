<?php
/**
 * Tests for receipt data builder.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Date_Formatter;
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
	 * Restore the WC payment gateways instance in case a test nulled it
	 * (the refund gateway-fallback tests intentionally null
	 * WC()->payment_gateways to exercise the snapshot-only path; without
	 * this restoration the next test's parent::setUp() -> rest_api_init
	 * would explode inside WC StoreApi's CheckoutSchema).
	 */
	public function tearDown(): void {
		if ( null === WC()->payment_gateways ) {
			WC()->payment_gateways = \WC_Payment_Gateways::instance();
		}
		parent::tearDown();
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
	 * Reset tax options for deterministic tax-summary fixtures.
	 *
	 * @param string $prices_include_tax Whether prices include tax.
	 */
	private function set_tax_fixture_options( string $prices_include_tax = 'no' ): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax );
		update_option( 'woocommerce_tax_display_cart', 'excl' );
		update_option( 'woocommerce_tax_total_display', 'itemized' );
	}

	/**
	 * Create a taxable simple product with the supplied regular price.
	 *
	 * @param string $price Product price.
	 *
	 * @return \WC_Product_Simple
	 */
	private function create_taxable_product_with_price( string $price ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Tax Fixture Product' );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_tax_status( 'taxable' );
		$product->save();

		return $product;
	}

	/**
	 * Get a tax summary row by rate id.
	 *
	 * @param array<int,array<string,mixed>> $summary Tax summary rows.
	 * @param int                           $rate_id Rate ID.
	 *
	 * @return array<string,mixed>
	 */
	private function get_tax_summary_row_by_rate_id( array $summary, int $rate_id ): array {
		foreach ( $summary as $row ) {
			if ( (string) $rate_id === (string) $row['code'] ) {
				return $row;
			}
		}

		$this->fail( 'Missing tax summary row for rate ' . $rate_id );
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

		$this->assertArrayNotHasKey( 'meta', $payload );
		$this->assertArrayNotHasKey( 'receipt', $payload );
		$this->assertArrayHasKey( 'wc_status', $payload['order'] );
		$this->assertArrayHasKey( 'status_label', $payload['order'] );
		$this->assertArrayHasKey( 'created_via', $payload['order'] );
		$this->assertSame( ! empty( $payload['tax_summary'] ), $payload['has_tax_summary'] );
	}

	/**
	 * Test order date sections use store timezone when available.
	 */
	public function test_build_formats_order_dates_in_store_timezone(): void {
		$order = wc_create_order();
		$order->set_date_created( strtotime( '2026-05-07 00:30:00 UTC' ) );
		$order->save();

		$pos_store = new class() {
			public function get_timezone(): string {
				return 'America/New_York';
			}
		};

		$payload = $this->builder->build( $order, 'live', $pos_store );

		$this->assertSame( '2026-05-06', $payload['order']['created']['date_ymd'] );
		$this->assertSame( 'America/New_York', $payload['presentation_hints']['timezone'] );
	}

	/**
	 * Test status_label is populated using wc_get_order_status_name().
	 */
	public function test_build_populates_status_label_from_wc_helper(): void {
		$order = OrderHelper::create_order();
		$order->set_status( 'on-hold' );
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		$this->assertSame( 'on-hold', $payload['order']['wc_status'] );
		$this->assertSame( wc_get_order_status_name( 'on-hold' ), $payload['order']['status_label'] );
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
	 * Test totals include item-count summaries.
	 *
	 * `totals.total_qty` and `totals.line_count` give packing-slip / kitchen-ticket
	 * templates the per-order quantity summary they can't compute in Mustache.
	 */
	public function test_build_totals_include_item_count_summaries(): void {
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'total_qty', $payload['totals'] );
		$this->assertArrayHasKey( 'line_count', $payload['totals'] );
		$this->assertIsNumeric( $payload['totals']['total_qty'] );
		$this->assertIsInt( $payload['totals']['line_count'] );

		$expected_qty   = array_sum( array_column( $payload['lines'], 'qty' ) );
		$expected_count = \count( $payload['lines'] );
		$this->assertEquals( $expected_qty, $payload['totals']['total_qty'] );
		$this->assertEquals( $expected_count, $payload['totals']['line_count'] );
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
	 * Test line items expose product attributes separately from formatted order-item meta.
	 */
	public function test_build_line_items_separate_product_attributes_from_order_item_meta(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$variation     = wc_get_product( $variation_ids[0] );
		$order         = OrderHelper::create_order( array( 'product' => $variation ) );
		$item          = array_values( $order->get_items( 'line_item' ) )[0];

		$item->add_meta_data( 'Gift wrap', '+$5.00', true );
		$item->save();

		$payload = $this->builder->build( $order, 'live' );
		$line    = $payload['lines'][0];

		$this->assertArrayHasKey( 'attributes', $line );
		$this->assertNotEmpty( $line['attributes'] );
		$this->assertContains( 'Gift wrap', array_column( $line['meta'], 'key' ) );
		$this->assertNotContains( 'Gift wrap', array_column( $line['attributes'], 'key' ) );
	}

	/**
	 * Test line-item receipt meta excludes private/internal order item meta.
	 */
	public function test_build_line_items_exclude_private_order_item_meta(): void {
		$product = ProductHelper::create_simple_product();
		$order   = OrderHelper::create_order( array( 'product' => $product ) );
		$item    = array_values( $order->get_items( 'line_item' ) )[0];

		$item->add_meta_data( 'Gift wrap', '+$5.00', true );
		$item->add_meta_data( '_woocommerce_pos_data', '{"price":"35","regular_price":"45","tax_status":"taxable"}', true );
		$item->add_meta_data( '_woocommerce_pos_uuid', 'ee59a549-7d74-492d-80d7-b9735d539a5b', true );
		$item->save();

		$payload   = $this->builder->build( $order, 'live' );
		$meta_keys = array_column( $payload['lines'][0]['meta'], 'key' );

		$this->assertContains( 'Gift wrap', $meta_keys );
		$this->assertNotContains( '_woocommerce_pos_data', $meta_keys );
		$this->assertNotContains( '_woocommerce_pos_uuid', $meta_keys );
	}

	/**
	 * Test presentation hints follow WooCommerce tax display settings.
	 */
	public function test_build_presentation_hints_reflect_tax_settings(): void {
		$order   = $this->create_taxed_order( 'itemized', 'yes' );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayNotHasKey( 'display_tax', $payload['presentation_hints'] );
		$this->assertSame( 'excl', $payload['tax']['display'] );
		$this->assertFalse( $payload['tax']['display_incl'] );
		$this->assertTrue( $payload['tax']['display_excl'] );
		$this->assertSame( 'itemized', $payload['tax']['breakdown'] );
		$this->assertTrue( $payload['tax']['breakdown_itemized'] );
		$this->assertTrue( $payload['presentation_hints']['prices_entered_with_tax'] );
	}


	/**
	 * Test tax section exposes branchable hidden/single/itemized states.
	 */
	public function test_build_tax_section_reflects_tax_breakdown_modes(): void {
		$order = $this->create_taxed_order( 'single', 'no' );

		$single = $this->builder->build( $order, 'live' );
		$this->assertSame( 'single', $single['tax']['breakdown'] );
		$this->assertTrue( $single['tax']['breakdown_single'] );
		$this->assertFalse( $single['tax']['breakdown_hidden'] );
		$this->assertFalse( $single['tax']['breakdown_itemized'] );

		update_option( 'woocommerce_tax_total_display', 'itemized' );
		$itemized = $this->builder->build( $order, 'live' );
		$this->assertSame( 'itemized', $itemized['tax']['breakdown'] );
		$this->assertTrue( $itemized['tax']['breakdown_itemized'] );

		update_option( 'woocommerce_calc_taxes', 'no' );
		$hidden = $this->builder->build( $order, 'live' );
		$this->assertSame( 'hidden', $hidden['tax']['breakdown'] );
		$this->assertTrue( $hidden['tax']['breakdown_hidden'] );
	}

	/**
	 * Existing-order receipts keep the order's currency (financial record)
	 * but should use the store's locale for date/number formatting so the
	 * presentation matches the store's region rather than the site default.
	 */
	public function test_build_uses_store_locale_when_store_provides_one(): void {
		$order = $this->create_taxed_order( 'itemized', 'yes' );
		$pos_store = new class() {
			public function get_locale(): string {
				return 'fr_FR';
			}
		};

		$payload = $this->builder->build( $order, 'live', $pos_store );

		$this->assertEquals( 'fr_FR', $payload['presentation_hints']['locale'] );
		// Currency stays from the order, not the store.
		$this->assertEquals( $order->get_currency(), $payload['order']['currency'] );
	}


	/**
	 * Existing-order receipt dates should use the store locale, not the site locale.
	 */
	public function test_build_formats_order_dates_with_store_locale(): void {
		$order = wc_create_order();
		$order->set_date_created( strtotime( '2026-01-15 10:30:00 UTC' ) );
		$order->save();

		$pos_store = new class() {
			public function get_locale(): string {
				return 'es_ES';
			}

			public function get_timezone(): string {
				return 'Europe/Madrid';
			}
		};

		$payload = $this->builder->build( $order, 'live', $pos_store );
		$expected_created = Receipt_Date_Formatter::from_timestamp(
			$order->get_date_created()->getTimestamp(),
			new \DateTimeZone( 'Europe/Madrid' ),
			'es_ES'
		);

		$this->assertEquals( 'es_ES', $payload['presentation_hints']['locale'] );
		$this->assertEquals( $expected_created, $payload['order']['created'] );
		$normalized_time = strtolower( $payload['order']['created']['time'] );
		$this->assertStringNotContainsString( 'am', $normalized_time );
		$this->assertStringNotContainsString( 'pm', $normalized_time );
	}

	/**
	 * Existing-order receipts should keep the order currency while taking
	 * price-formatting and tax-presentation hints from the selected store.
	 */
	public function test_build_uses_store_price_and_tax_presentation_settings(): void {
		$order = $this->create_taxed_order( 'itemized', 'no' );
		$pos_store = new class() {
			public function get_locale(): string {
				return 'de_DE';
			}

			public function get_currency_position(): string {
				return 'right_space';
			}

			public function get_price_thousand_separator(): string {
				return '.';
			}

			public function get_price_decimal_separator(): string {
				return ',';
			}

			public function get_price_number_of_decimals(): int {
				return 3;
			}

			public function get_price_display_suffix(): string {
				return 'inkl. MwSt.';
			}

			public function get_tax_display_cart(): string {
				return 'incl';
			}

			public function get_tax_total_display(): string {
				return 'single';
			}

			public function get_tax_round_at_subtotal(): string {
				return 'yes';
			}

			public function get_prices_include_tax(): string {
				return 'yes';
			}
		};

		$payload = $this->builder->build( $order, 'live', $pos_store );
		$hints   = $payload['presentation_hints'];

		$this->assertEquals( $order->get_currency(), $payload['order']['currency'] );
		$this->assertEquals( get_woocommerce_currency_symbol( $order->get_currency() ), $hints['currency_symbol'] );
		$this->assertEquals( 'right_space', $hints['currency_position'] );
		$this->assertEquals( '.', $hints['price_thousand_separator'] );
		$this->assertEquals( ',', $hints['price_decimal_separator'] );
		$this->assertEquals( 3, $hints['price_num_decimals'] );
		$this->assertEquals( 'inkl. MwSt.', $hints['price_display_suffix'] );
		$this->assertArrayNotHasKey( 'display_tax', $hints );
		$this->assertSame( 'incl', $payload['tax']['display'] );
		$this->assertTrue( $payload['tax']['display_incl'] );
		$this->assertSame( 'single', $payload['tax']['breakdown'] );
		$this->assertTrue( $payload['tax']['breakdown_single'] );
		$this->assertTrue( $hints['prices_entered_with_tax'] );
		$this->assertEquals( 'yes', $hints['rounding_mode'] );
		$this->assertArrayNotHasKey( 'order_barcode_type', $hints );
	}

	/**
	 * Explicit blank store formatting values should override non-empty globals.
	 */
	public function test_build_preserves_empty_store_price_formatting_overrides(): void {
		$order                 = $this->create_taxed_order( 'itemized', 'no' );
		$original_thousand_sep = get_option( 'woocommerce_price_thousand_sep' );
		$original_price_suffix = get_option( 'woocommerce_price_display_suffix' );
		$pos_store             = new class() {
			public function get_price_thousand_separator(): string {
				return '';
			}

			public function get_price_display_suffix(): string {
				return '';
			}
		};

		try {
			update_option( 'woocommerce_price_thousand_sep', ',' );
			update_option( 'woocommerce_price_display_suffix', ' including tax' );

			$payload = $this->builder->build( $order, 'live', $pos_store );
			$hints   = $payload['presentation_hints'];

			$this->assertSame( '', $hints['price_thousand_separator'] );
			$this->assertSame( '', $hints['price_display_suffix'] );
			$this->assertArrayNotHasKey( 'order_barcode_type', $hints );
		} finally {
			update_option( 'woocommerce_price_thousand_sep', $original_thousand_sep );
			update_option( 'woocommerce_price_display_suffix', $original_price_suffix );
		}
	}

	/**
	 * Empty store option values should fall back to WooCommerce defaults.
	 */
	public function test_build_falls_back_for_empty_store_option_values(): void {
		$original_calc_taxes        = get_option( 'woocommerce_calc_taxes' );
		$original_tax_display_cart  = get_option( 'woocommerce_tax_display_cart' );
		$original_tax_total_display = get_option( 'woocommerce_tax_total_display' );
		$original_tax_rounding      = get_option( 'woocommerce_tax_round_at_subtotal' );
		$original_prices_tax        = get_option( 'woocommerce_prices_include_tax' );
		$original_currency_pos      = get_option( 'woocommerce_currency_pos' );
		$order                      = $this->create_taxed_order( 'single', 'yes' );
		$pos_store                  = new class() {
			public function get_calc_taxes(): string {
				return '';
			}

			public function get_tax_display_cart(): string {
				return '';
			}

			public function get_tax_total_display(): string {
				return '';
			}

			public function get_tax_round_at_subtotal(): string {
				return '';
			}

			public function get_prices_include_tax(): string {
				return '';
			}

			public function get_currency_position(): string {
				return '';
			}
		};

		try {
			update_option( 'woocommerce_calc_taxes', 'yes' );
			update_option( 'woocommerce_tax_display_cart', 'incl' );
			update_option( 'woocommerce_tax_total_display', 'single' );
			update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
			update_option( 'woocommerce_prices_include_tax', 'yes' );
			update_option( 'woocommerce_currency_pos', 'right' );

			$payload = $this->builder->build( $order, 'live', $pos_store );
			$hints   = $payload['presentation_hints'];

			$this->assertArrayNotHasKey( 'display_tax', $hints );
			$this->assertSame( 'incl', $payload['tax']['display'] );
			$this->assertTrue( $payload['tax']['display_incl'] );
			$this->assertSame( 'single', $payload['tax']['breakdown'] );
			$this->assertTrue( $payload['tax']['breakdown_single'] );
			$this->assertTrue( $hints['prices_entered_with_tax'] );
			$this->assertEquals( 'yes', $hints['rounding_mode'] );
			$this->assertEquals( 'right', $hints['currency_position'] );
		} finally {
			update_option( 'woocommerce_calc_taxes', $original_calc_taxes );
			update_option( 'woocommerce_tax_display_cart', $original_tax_display_cart );
			update_option( 'woocommerce_tax_total_display', $original_tax_total_display );
			update_option( 'woocommerce_tax_round_at_subtotal', $original_tax_rounding );
			update_option( 'woocommerce_prices_include_tax', $original_prices_tax );
			update_option( 'woocommerce_currency_pos', $original_currency_pos );
		}
	}

	/**
	 * Store display decimals should not round numeric line-item payload values.
	 */
	public function test_build_uses_calculation_precision_for_unit_values(): void {
		$original_num_decimals = get_option( 'woocommerce_price_num_decimals' );
		$pos_store             = new class() {
			public function get_price_number_of_decimals(): int {
				return 0;
			}
		};

		try {
			update_option( 'woocommerce_price_num_decimals', '2' );

			$product = new \WC_Product_Simple();
			$product->set_name( 'Calculation Precision Product' );
			$product->set_regular_price( '10.99' );
			$product->set_price( '10.99' );
			$product->save();

			$order = wc_create_order();
			$order->add_product( $product, 2 );
			$order->calculate_totals( true );
			$order->save();

			$payload = $this->builder->build( $order, 'live', $pos_store );
			$line    = $payload['lines'][0];

			$this->assertEquals( 0, $payload['presentation_hints']['price_num_decimals'] );
			$this->assertEquals( 10.99, (float) $line['unit_price_excl'] );
			$this->assertEquals( 10.99, (float) $line['unit_subtotal_excl'] );
		} finally {
			update_option( 'woocommerce_price_num_decimals', $original_num_decimals );
		}
	}

	/**
	 * Falls back to the site locale when the store does not expose
	 * get_locale or returns an empty value.
	 */
	public function test_build_falls_back_to_site_locale_when_store_lacks_locale(): void {
		$order = $this->create_taxed_order( 'itemized', 'yes' );
		$pos_store = new class() {
			public function get_locale(): string {
				return '';
			}
		};

		$payload = $this->builder->build( $order, 'live', $pos_store );

		$this->assertEquals( get_locale(), $payload['presentation_hints']['locale'] );
	}

	/**
	 * Test tax summary uses real net base for tax-inclusive prices.
	 */
	public function test_build_tax_summary_uses_real_net_base_for_tax_inclusive_prices(): void {
		$this->set_tax_fixture_options( 'yes' );
		$rate_id = TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.000',
				'name'     => 'US Tax',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);
		$product = $this->create_taxable_product_with_price( '45.00' );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );

		$payload = $this->builder->build( $order, 'live' );
		$row     = $this->get_tax_summary_row_by_rate_id( $payload['tax_summary'], $rate_id );

		$this->assertTrue( $payload['has_tax_summary'] );
		$this->assertEqualsWithDelta( 40.91, (float) $row['taxable_amount_excl'], 0.01 );
		$this->assertNotEqualsWithDelta( (float) $row['tax_amount'] / 0.10, (float) $row['taxable_amount_excl'], 0.001 );
	}

	/**
	 * Test tax summary uses post-discount line totals as taxable base.
	 */
	public function test_build_tax_summary_uses_post_discount_line_base(): void {
		$this->set_tax_fixture_options( 'no' );
		$rate_id = TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.000',
				'name'     => 'US Tax',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);
		$product = $this->create_taxable_product_with_price( '100.00' );
		$coupon  = new \WC_Coupon();
		$coupon->set_code( 'twenty-off' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 20 );
		$coupon->save();
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->apply_coupon( $coupon );
		$order->calculate_totals( true );

		$payload = $this->builder->build( $order, 'live' );
		$row     = $this->get_tax_summary_row_by_rate_id( $payload['tax_summary'], $rate_id );

		$this->assertEqualsWithDelta( 80.00, (float) $row['taxable_amount_excl'], 0.01 );
	}

	/**
	 * Test each rate receives the full line base when multiple rates apply.
	 */
	public function test_build_tax_summary_counts_full_line_base_for_each_rate(): void {
		$this->set_tax_fixture_options( 'no' );
		$rate_a = TaxHelper::create_tax_rate(
			array( 'country' => 'US', 'rate' => '10.000', 'name' => 'State Tax', 'priority' => 1, 'compound' => false, 'shipping' => true )
		);
		$rate_b = TaxHelper::create_tax_rate(
			array( 'country' => 'US', 'rate' => '5.000', 'name' => 'Local Tax', 'priority' => 2, 'compound' => false, 'shipping' => true )
		);
		$product = $this->create_taxable_product_with_price( '100.00' );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );

		$payload = $this->builder->build( $order, 'live' );

		$this->assertEqualsWithDelta( 100.00, (float) $this->get_tax_summary_row_by_rate_id( $payload['tax_summary'], $rate_a )['taxable_amount_excl'], 0.01 );
		$this->assertEqualsWithDelta( 100.00, (float) $this->get_tax_summary_row_by_rate_id( $payload['tax_summary'], $rate_b )['taxable_amount_excl'], 0.01 );
	}

	/**
	 * Test taxable fees and shipping contribute to the tax summary base.
	 */
	public function test_build_tax_summary_includes_taxable_fees_and_shipping(): void {
		$this->set_tax_fixture_options( 'no' );
		$rate_id = TaxHelper::create_tax_rate(
			array( 'country' => 'US', 'rate' => '10.000', 'name' => 'US Tax', 'priority' => 1, 'compound' => false, 'shipping' => true )
		);
		$product = $this->create_taxable_product_with_price( '100.00' );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Taxed Fee' );
		$fee->set_amount( 2.50 );
		$fee->set_total( 2.50 );
		$fee->set_tax_status( 'taxable' );
		$order->add_item( $fee );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Taxed Shipping' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( 10.00 );
		$order->add_item( $shipping );
		$order->calculate_totals( true );

		$payload = $this->builder->build( $order, 'live' );
		$row     = $this->get_tax_summary_row_by_rate_id( $payload['tax_summary'], $rate_id );

		$this->assertEqualsWithDelta( 112.50, (float) $row['taxable_amount_excl'], 0.01 );
	}

	/**
	 * Test compound tax rows use pure pre-tax net base for v1.
	 */
	public function test_build_tax_summary_compound_rate_uses_pre_tax_net_base(): void {
		$this->set_tax_fixture_options( 'no' );
		TaxHelper::create_tax_rate(
			array( 'country' => 'US', 'rate' => '10.000', 'name' => 'Base Tax', 'priority' => 1, 'compound' => false, 'shipping' => true )
		);
		$compound_rate = TaxHelper::create_tax_rate(
			array( 'country' => 'US', 'rate' => '5.000', 'name' => 'Compound Tax', 'priority' => 2, 'compound' => true, 'shipping' => true )
		);
		$product = $this->create_taxable_product_with_price( '100.00' );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );

		$payload = $this->builder->build( $order, 'live' );
		$row     = $this->get_tax_summary_row_by_rate_id( $payload['tax_summary'], $compound_rate );

		$this->assertTrue( $row['compound'] );
		$this->assertEqualsWithDelta( 100.00, (float) $row['taxable_amount_excl'], 0.01 );
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

	/**
	 * Test zero-quantity lines do not force quantity to one.
	 */
	public function test_build_keeps_zero_quantity_and_avoids_division_by_zero(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Zero Qty Product' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order->save();
		$item = array_values( $order->get_items() )[0];
		wc_update_order_item_meta( $item->get_id(), '_qty', 0 );
		$order = wc_get_order( $order->get_id() );

		$payload = $this->builder->build( $order, 'live' );
		$line    = $payload['lines'][0];

		$this->assertEquals( 0.0, (float) $line['qty'] );
		$this->assertEquals( 0.0, (float) $line['unit_price_incl'] );
		$this->assertEquals( 0.0, (float) $line['unit_price_excl'] );
	}

	/**
	 * Test build includes new store fields.
	 */
	public function test_build_includes_new_store_fields(): void {
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );
		$store   = $payload['store'];

		$this->assertArrayHasKey( 'logo', $store );
		$this->assertArrayHasKey( 'opening_hours', $store );
		$this->assertArrayHasKey( 'opening_hours_vertical', $store );
		$this->assertArrayHasKey( 'opening_hours_inline', $store );
		$this->assertArrayHasKey( 'opening_hours_notes', $store );
		$this->assertArrayHasKey( 'personal_notes', $store );
		$this->assertArrayHasKey( 'policies_and_conditions', $store );
		$this->assertArrayHasKey( 'footer_imprint', $store );
	}

	/**
	 * Test missing historical store IDs do not mix in current store details.
	 */
	public function test_build_does_not_hydrate_default_store_for_missing_historical_store(): void {
		$missing_store_id      = 987654;
		$original_store_address = get_option( 'woocommerce_store_address' );
		$order                 = OrderHelper::create_order();
		$order->update_meta_data( '_pos_store', $missing_store_id );
		$order->save();

		$store_filter = static function ( $store, $the_store ) use ( $missing_store_id ) {
			if ( $missing_store_id === (int) $the_store ) {
				return false;
			}

			return $store;
		};

		try {
			update_option( 'woocommerce_store_address', '1 Current Store Road' );
			add_filter( 'woocommerce_pos_get_store', $store_filter, 10, 3 );

			$payload = $this->builder->build( $order, 'live' );
			$store   = $payload['store'];

			$this->assertSame( $missing_store_id, $store['id'] );
			// translators: %d: Historical POS store ID that no longer exists.
			$expected_store_name = sprintf( __( 'Store #%d', 'woocommerce-pos' ), $missing_store_id );
			$this->assertSame( $expected_store_name, $store['name'] );
			$this->assertSame( '', $store['address']['address_1'] );
		} finally {
			remove_filter( 'woocommerce_pos_get_store', $store_filter, 10 );
			update_option( 'woocommerce_store_address', $original_store_address );
		}
	}

	/**
	 * Test the order section includes rich date data for created/paid/completed/printed.
	 */
	public function test_build_includes_order_date_sections(): void {
		$order   = wc_get_order( OrderHelper::create_order() );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'order', $payload );
		$this->assertSame( (string) $order->get_order_number(), $payload['order']['number'] );
		$this->assertSame( (string) $order->get_currency(), $payload['order']['currency'] );
		$this->assertSame( (string) $order->get_customer_note(), $payload['order']['customer_note'] );

		foreach ( array( 'created', 'paid', 'completed', 'printed' ) as $field ) {
			$this->assertArrayHasKey( $field, $payload['order'] );
			$this->assertArrayHasKey( 'datetime_full', $payload['order'][ $field ] );
			$this->assertArrayHasKey( 'date_ymd', $payload['order'][ $field ] );
			$this->assertArrayHasKey( 'weekday_short', $payload['order'][ $field ] );
		}

		$this->assertNotSame( '', $payload['order']['created']['datetime'] );
	}

	/**
	 * Test missing paid and completed dates return blank display fields.
	 */
	public function test_build_blanks_missing_paid_and_completed_dates(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_customer_note( 'No payment yet' );
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		$this->assertSame( '', $payload['order']['paid']['datetime'] );
		$this->assertSame( '', $payload['order']['paid']['date_long'] );
		$this->assertSame( '', $payload['order']['completed']['datetime'] );
		$this->assertSame( '', $payload['order']['completed']['weekday_long'] );
	}

	/**
	 * Test order.printed is populated at render time even when paid/completed are blank.
	 */
	public function test_build_order_printed_is_render_time(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		// Printed has data despite the order having no paid/completed dates,
		// proving it's render-time rather than derived from order metadata.
		$this->assertArrayHasKey( 'printed', $payload['order'] );
		$this->assertNotSame( '', $payload['order']['printed']['datetime'] );
		$this->assertNotSame( '', $payload['order']['printed']['date_ymd'] );
		$this->assertSame( '', $payload['order']['paid']['datetime'] );
		$this->assertSame( '', $payload['order']['completed']['datetime'] );
	}

	/**
	 * Build an order with a non-zero total and a given status — WC's
	 * needs_payment() requires both `total > 0` and a valid status, so the
	 * empty-order helper isn't enough to exercise the new field.
	 *
	 * @param string $status WC order status (e.g. 'pending', 'completed').
	 *
	 * @return \WC_Order
	 */
	private function create_order_with_total( string $status ): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '12.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * Test order.needs_payment is true when the order is in a status that needs payment.
	 */
	public function test_build_order_needs_payment_is_true_for_pending_order(): void {
		$order = $this->create_order_with_total( 'pending' );

		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'needs_payment', $payload['order'] );
		$this->assertTrue( $payload['order']['needs_payment'] );
	}

	/**
	 * Test order.needs_payment is false when the order is in a status that does not need payment.
	 */
	public function test_build_order_needs_payment_is_false_for_completed_order(): void {
		$order = $this->create_order_with_total( 'completed' );

		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'needs_payment', $payload['order'] );
		$this->assertFalse( $payload['order']['needs_payment'] );
	}

	/**
	 * Test order.payment_url is the WC order-pay URL and is populated regardless of status.
	 */
	public function test_build_order_payment_url_is_wc_checkout_payment_url(): void {
		$order = $this->create_order_with_total( 'pending' );

		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'payment_url', $payload['order'] );
		$this->assertSame( $order->get_checkout_payment_url(), $payload['order']['payment_url'] );
		// The order-pay endpoint always tacks on the pay_for_order flag and the
		// order's payment key — without these the WC checkout-pay handler 404s.
		$this->assertStringContainsString( 'pay_for_order=true', $payload['order']['payment_url'] );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $payload['order']['payment_url'] );
	}

	/**
	 * Test legacy string opening hours remain available in live receipt payloads.
	 */
	public function test_build_preserves_legacy_string_opening_hours_without_notes_getter(): void {
		$order        = OrderHelper::create_order();
		$legacy_hours = 'Mon-Fri 09:00-17:00';

		$store_filter = static function () use ( $legacy_hours ) {
			return new class( $legacy_hours ) {
				private string $legacy_hours;

				public function __construct( string $legacy_hours ) {
					$this->legacy_hours = $legacy_hours;
				}

				public function get_opening_hours(): string {
					return $this->legacy_hours;
				}

				public function get_personal_notes(): string {
					return '';
				}

				public function get_policies_and_conditions(): string {
					return '';
				}

				public function get_footer_imprint(): string {
					return '';
				}
			};
		};

		try {
			add_filter( 'woocommerce_pos_get_store', $store_filter );

			$payload = $this->builder->build( $order, 'live' );
			$store   = $payload['store'];

			$this->assertSame( $legacy_hours, $store['opening_hours'] );
			$this->assertNull( $store['opening_hours_vertical'] );
			$this->assertNull( $store['opening_hours_inline'] );
			$this->assertNull( $store['opening_hours_notes'] );
		} finally {
			remove_filter( 'woocommerce_pos_get_store', $store_filter );
		}
	}

	/**
	 * Test site logo fallback is exposed when not explicitly disabled.
	 */
	public function test_build_uses_site_logo_when_available_and_not_opted_out(): void {
		$order    = OrderHelper::create_order();
		$store_id = $this->factory->post->create();
		$logo_url = 'https://example.com/site-logo.png';
		$logo_id  = 987654;

		$image_downsize_filter = static function ( $out, $id ) use ( $logo_id, $logo_url ) {
			if ( $logo_id !== (int) $id ) {
				return $out;
			}

			return array( $logo_url, 320, 120, true );
		};

		$store_filter = static function () use ( $store_id ) {
			return new class( $store_id ) {
				private int $id;

				public function __construct( int $id ) {
					$this->id = $id;
				}

				public function get_id(): int {
					return $this->id;
				}

				public function get_logo_image_src( $size = 'full' ) {
					return false;
				}

				public function get_opening_hours(): array {
					return array();
				}

				public function get_personal_notes(): string {
					return '';
				}

				public function get_policies_and_conditions(): string {
					return '';
				}

				public function get_footer_imprint(): string {
					return '';
				}
			};
		};

		try {
			set_theme_mod( 'custom_logo', $logo_id );
			add_filter( 'image_downsize', $image_downsize_filter, 10, 3 );
			add_filter( 'woocommerce_pos_get_store', $store_filter );

			$payload = $this->builder->build( $order, 'live' );
			$this->assertSame( $logo_url, $payload['store']['logo'] );
		} finally {
			remove_filter( 'woocommerce_pos_get_store', $store_filter );
			remove_filter( 'image_downsize', $image_downsize_filter, 10 );
			remove_theme_mod( 'custom_logo' );
		}
	}

	/**
	 * Test site logo fallback is hidden when store opts out.
	 */
	public function test_build_hides_site_logo_when_store_opts_out(): void {
		$order    = OrderHelper::create_order();
		$store_id = $this->factory->post->create();
		$logo_url = 'https://example.com/site-logo-optout.png';
		$logo_id  = 987655;

		update_post_meta( $store_id, '_use_site_logo', 'no' );

		$image_downsize_filter = static function ( $out, $id ) use ( $logo_id, $logo_url ) {
			if ( $logo_id !== (int) $id ) {
				return $out;
			}

			return array( $logo_url, 320, 120, true );
		};

		$store_filter = static function () use ( $store_id ) {
			return new class( $store_id ) {
				private int $id;

				public function __construct( int $id ) {
					$this->id = $id;
				}

				public function get_id(): int {
					return $this->id;
				}

				public function get_logo_image_src( $size = 'full' ) {
					return false;
				}

				public function get_opening_hours(): array {
					return array();
				}

				public function get_personal_notes(): string {
					return '';
				}

				public function get_policies_and_conditions(): string {
					return '';
				}

				public function get_footer_imprint(): string {
					return '';
				}
			};
		};

		try {
			set_theme_mod( 'custom_logo', $logo_id );
			add_filter( 'image_downsize', $image_downsize_filter, 10, 3 );
			add_filter( 'woocommerce_pos_get_store', $store_filter );

			$payload = $this->builder->build( $order, 'live' );
			$this->assertNull( $payload['store']['logo'] );
		} finally {
			remove_filter( 'woocommerce_pos_get_store', $store_filter );
			remove_filter( 'image_downsize', $image_downsize_filter, 10 );
			remove_theme_mod( 'custom_logo' );
		}
	}

	/**
	 * Test store block includes tax_ids array sourced from WCPOS general settings.
	 */
	public function test_store_block_includes_tax_ids_array_from_settings(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'store_tax_ids' => array(
					array(
						'type'    => 'eu_vat',
						'value'   => 'DE123456789',
						'country' => 'DE',
					),
				),
			)
		);
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'tax_ids', $payload['store'] );
		$this->assertCount( 1, $payload['store']['tax_ids'] );
		$this->assertSame( 'eu_vat', $payload['store']['tax_ids'][0]['type'] );
		$this->assertSame( 'DE123456789', $payload['store']['tax_ids'][0]['value'] );
		$this->assertSame( 'DE', $payload['store']['tax_ids'][0]['country'] );
		$this->assertSame( 'VAT ID', $payload['store']['tax_ids'][0]['label'] );

		$this->assertArrayNotHasKey( 'tax_id', $payload['store'] );
	}

	/**
	 * Test store block emits empty tax_ids when settings are blank.
	 */
	public function test_store_block_emits_empty_tax_ids_when_settings_blank(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		$this->assertSame( array(), $payload['store']['tax_ids'] );
		$this->assertArrayNotHasKey( 'tax_id', $payload['store'] );
	}

	/**
	 * Test explicit store logo is preserved when site logo is disabled.
	 */
	public function test_build_preserves_explicit_logo_when_site_logo_is_disabled(): void {
		$order         = OrderHelper::create_order();
		$store_id      = $this->factory->post->create();
		$logo_url      = 'https://example.com/store-logo.png';
		$logo_id       = $this->factory->post->create(
			array(
				'post_type' => 'attachment',
			)
		);
		$site_logo_url = 'https://example.com/site-logo-disabled.png';
		$site_logo_id  = $this->factory->post->create(
			array(
				'post_type' => 'attachment',
			)
		);

		update_post_meta( $store_id, '_use_site_logo', 'no' );
		update_post_meta( $store_id, '_thumbnail_id', $logo_id );

		$image_downsize_filter = static function ( $out, $id ) use ( $logo_id, $logo_url, $site_logo_id, $site_logo_url ) {
			if ( $logo_id === (int) $id ) {
				return array( $logo_url, 320, 120, true );
			}

			if ( $site_logo_id !== (int) $id ) {
				return $out;
			}

			return array( $site_logo_url, 320, 120, true );
		};

		$store_filter = static function () use ( $store_id ) {
			return new class( $store_id ) {
				private int $id;

				public function __construct( int $id ) {
					$this->id = $id;
				}

				public function get_id(): int {
					return $this->id;
				}

				public function get_logo_image_src( $size = 'full' ) {
					return false;
				}

				public function get_opening_hours(): array {
					return array();
				}

				public function get_personal_notes(): string {
					return '';
				}

				public function get_policies_and_conditions(): string {
					return '';
				}

				public function get_footer_imprint(): string {
					return '';
				}
			};
		};

		try {
			set_theme_mod( 'custom_logo', $site_logo_id );
			add_filter( 'image_downsize', $image_downsize_filter, 10, 3 );
			add_filter( 'woocommerce_pos_get_store', $store_filter );

			$payload = $this->builder->build( $order, 'live' );
			$this->assertSame( $logo_url, $payload['store']['logo'] );
		} finally {
			remove_filter( 'woocommerce_pos_get_store', $store_filter );
			remove_filter( 'image_downsize', $image_downsize_filter, 10 );
			remove_theme_mod( 'custom_logo' );
		}
	}

	/**
	 * Test payments use transaction_id (not deprecated reference) and wire it from the order.
	 */
	public function test_build_payments_use_transaction_id_from_order(): void {
		$order = OrderHelper::create_order();
		$order->set_transaction_id( 'txn_12345' );
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		$this->assertNotEmpty( $payload['payments'] );
		$payment = $payload['payments'][0];
		$this->assertArrayHasKey( 'transaction_id', $payment );
		$this->assertArrayNotHasKey( 'reference', $payment );
		$this->assertSame( 'txn_12345', $payment['transaction_id'] );
	}

	/**
	 * Test tax summary entries include the compound flag.
	 */
	public function test_build_tax_summary_includes_compound_flag(): void {
		$order   = $this->create_taxed_order( 'itemized', 'no' );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertNotEmpty( $payload['tax_summary'] );
		foreach ( $payload['tax_summary'] as $row ) {
			$this->assertArrayHasKey( 'compound', $row );
			$this->assertIsBool( $row['compound'] );
		}
	}

	/**
	 * Test line tax rows expose a human-readable label and numeric rate when WC_Tax can resolve them.
	 */
	public function test_build_line_taxes_resolve_label_and_rate(): void {
		$order   = $this->create_taxed_order( 'itemized', 'no' );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertNotEmpty( $payload['lines'] );
		$line = $payload['lines'][0];
		$this->assertNotEmpty( $line['taxes'] );
		$tax = $line['taxes'][0];
		$this->assertSame( 'US Tax', $tax['label'] );
		$this->assertNotNull( $tax['rate'] );
		$this->assertEqualsWithDelta( 10.0, (float) $tax['rate'], 0.001 );
	}

	/**
	 * Test discounts emit one row per coupon code instead of a single synthetic row.
	 */
	public function test_build_emits_per_coupon_discount_rows(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Item' );
		$product->set_regular_price( '50.00' );
		$product->save();

		$coupon_a = new \WC_Coupon();
		$coupon_a->set_code( 'CODE_A' );
		$coupon_a->set_amount( 5 );
		$coupon_a->set_discount_type( 'fixed_cart' );
		$coupon_a->set_description( 'Band discount 25–75' );
		$coupon_a->save();

		$coupon_b = new \WC_Coupon();
		$coupon_b->set_code( 'CODE_B' );
		$coupon_b->set_amount( 10 );
		$coupon_b->set_discount_type( 'fixed_cart' );
		$coupon_b->save();

		$coupon_c = new \WC_Coupon();
		$coupon_c->set_code( 'CODE_C' );
		$coupon_c->set_amount( 7 );
		$coupon_c->set_discount_type( 'fixed_cart' );
		$coupon_c->set_description( 'CODE_C' );
		$coupon_c->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->apply_coupon( 'CODE_A' );
		$order->apply_coupon( 'CODE_B' );
		$order->apply_coupon( 'CODE_C' );
		$order->calculate_totals();
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		$this->assertCount( 3, $payload['discounts'] );
		foreach ( $payload['discounts'] as $discount ) {
			$this->assertArrayHasKey( 'code', $discount );
			$this->assertArrayNotHasKey( 'codes', $discount );
		}

		$discounts_by_code = array_column( $payload['discounts'], null, 'code' );
		$this->assertArrayHasKey( wc_format_coupon_code( 'CODE_A' ), $discounts_by_code );
		$this->assertArrayHasKey( wc_format_coupon_code( 'CODE_B' ), $discounts_by_code );
		$this->assertArrayHasKey( wc_format_coupon_code( 'CODE_C' ), $discounts_by_code );
		$this->assertSame( 'Band discount 25–75', $discounts_by_code[ wc_format_coupon_code( 'CODE_A' ) ]['label'] );
		$this->assertSame( wc_format_coupon_code( 'CODE_B' ), $discounts_by_code[ wc_format_coupon_code( 'CODE_B' ) ]['label'] );
		$this->assertSame( wc_format_coupon_code( 'CODE_C' ), $discounts_by_code[ wc_format_coupon_code( 'CODE_C' ) ]['label'] );
	}

	/**
	 * Test shipping emits one row per shipping method, with method_id, taxes and meta.
	 */
	public function test_build_emits_per_shipping_method_rows(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Shippable' );
		$product->set_regular_price( '20.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );

		$ship_a = new \WC_Order_Item_Shipping();
		$ship_a->set_method_title( 'Standard' );
		$ship_a->set_method_id( 'flat_rate' );
		$ship_a->set_total( 5 );
		$order->add_item( $ship_a );

		$ship_b = new \WC_Order_Item_Shipping();
		$ship_b->set_method_title( 'Express' );
		$ship_b->set_method_id( 'flat_rate' );
		$ship_b->set_total( 12 );
		$order->add_item( $ship_b );

		$order->calculate_totals();
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		$this->assertCount( 2, $payload['shipping'] );
		$labels = array_column( $payload['shipping'], 'label' );
		$this->assertContains( 'Standard', $labels );
		$this->assertContains( 'Express', $labels );
		foreach ( $payload['shipping'] as $row ) {
			$this->assertArrayHasKey( 'method_id', $row );
			$this->assertArrayHasKey( 'taxes', $row );
			$this->assertArrayHasKey( 'meta', $row );
		}
	}

	/**
	 * Test fees emit meta and taxes alongside the totals.
	 */
	public function test_build_fees_emit_meta_and_taxes(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Item' );
		$product->set_regular_price( '20.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Service Fee' );
		$fee->set_amount( '3.00' );
		$fee->set_total( '3.00' );
		$order->add_item( $fee );

		$order->calculate_totals();
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		$this->assertCount( 1, $payload['fees'] );
		$this->assertArrayHasKey( 'meta', $payload['fees'][0] );
		$this->assertArrayHasKey( 'taxes', $payload['fees'][0] );
		$this->assertIsArray( $payload['fees'][0]['meta'] );
		$this->assertIsArray( $payload['fees'][0]['taxes'] );
	}

	/**
	 * Test refunds[] block is emitted with line items and amounts.
	 */
	public function test_build_includes_refunds_block_with_line_items(): void {
		$order = $this->create_taxed_order();

		$line_item    = array_values( $order->get_items() )[0];
		$line_item_id = $line_item->get_id();
		$line_taxes   = $line_item->get_taxes();

		wc_create_refund(
			array(
				'amount'     => '12.10',
				'reason'     => 'Customer changed mind',
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$line_item_id => array(
						'qty'          => 1,
						'refund_total' => 11.00,
						'refund_tax'   => $line_taxes['total'],
					),
				),
			)
		);

		$payload = $this->builder->build( wc_get_order( $order->get_id() ), 'live' );

		$this->assertNotEmpty( $payload['refunds'] );
		$refund = $payload['refunds'][0];
		$this->assertArrayHasKey( 'id', $refund );
		$this->assertArrayHasKey( 'amount', $refund );
		$this->assertArrayHasKey( 'reason', $refund );
		$this->assertArrayHasKey( 'lines', $refund );
		$this->assertSame( 'Customer changed mind', $refund['reason'] );
		$this->assertEqualsWithDelta( 12.10, (float) $refund['amount'], 0.001 );
		$this->assertNotEmpty( $refund['lines'] );
		$this->assertEqualsWithDelta( 1.0, (float) $refund['lines'][0]['qty'], 0.001 );

		// Schema v1.4.0: per-line tax sides + taxes[] passthrough.
		$this->assertArrayHasKey( 'total_incl', $refund['lines'][0] );
		$this->assertArrayHasKey( 'total_excl', $refund['lines'][0] );
		$this->assertArrayHasKey( 'taxes', $refund['lines'][0] );
		$this->assertNotEmpty( $refund['lines'][0]['taxes'] );
		$this->assertEqualsWithDelta( 1.10, (float) $refund['lines'][0]['taxes'][0]['amount'], 0.001 );

		// Schema v1.4.0: refund-level totals.
		$this->assertArrayHasKey( 'subtotal', $refund );
		$this->assertArrayHasKey( 'tax_total', $refund );
		$this->assertArrayHasKey( 'shipping_total', $refund );
		$this->assertArrayHasKey( 'shipping_tax', $refund );

		// Schema v1.4.0: refunded fee/shipping arrays exist (may be empty here).
		$this->assertArrayHasKey( 'fees', $refund );
		$this->assertArrayHasKey( 'shipping', $refund );

		// Schema v1.4.0: Pro audit fields exist (empty strings when no Pro meta).
		$this->assertArrayHasKey( 'destination', $refund );
		$this->assertArrayHasKey( 'gateway_id', $refund );
		$this->assertArrayHasKey( 'gateway_title', $refund );
		$this->assertArrayHasKey( 'processing_mode', $refund );
	}

	/**
	 * Test that refunded fee and shipping rows surface in refunds[].fees[] / refunds[].shipping[]
	 * with the new schema v1.4.0 fields (label, total, total_incl, total_excl, taxes, method_id).
	 */
	public function test_build_refund_includes_fee_and_shipping_rows(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Refundable' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Booking fee' );
		$fee->set_total( '5.00' );
		$order->add_item( $fee );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( '3.00' );
		$order->add_item( $shipping );

		$order->calculate_totals();
		$order->save();

		$items       = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
		$line_id     = 0;
		$fee_id      = 0;
		$shipping_id = 0;
		foreach ( $items as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$line_id = $item->get_id();
			} elseif ( $item instanceof \WC_Order_Item_Fee ) {
				$fee_id = $item->get_id();
			} elseif ( $item instanceof \WC_Order_Item_Shipping ) {
				$shipping_id = $item->get_id();
			}
		}

		wc_create_refund(
			array(
				'amount'     => '18.00',
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$line_id     => array(
						'qty'          => 1,
						'refund_total' => 10.00,
						'refund_tax'   => array(),
					),
					$fee_id      => array(
						'refund_total' => 5.00,
						'refund_tax'   => array(),
					),
					$shipping_id => array(
						'refund_total' => 3.00,
						'refund_tax'   => array(),
					),
				),
			)
		);

		$payload = $this->builder->build( wc_get_order( $order->get_id() ), 'live' );
		$refund  = $payload['refunds'][0];

		$this->assertNotEmpty( $refund['fees'] );
		$fee_row = $refund['fees'][0];
		$this->assertSame( 'Booking fee', $fee_row['label'] );
		$this->assertEqualsWithDelta( 5.00, (float) $fee_row['total'], 0.001 );
		$this->assertEqualsWithDelta( 5.00, (float) $fee_row['total_excl'], 0.001 );
		$this->assertEqualsWithDelta( 5.00, (float) $fee_row['total_incl'], 0.001 );
		$this->assertSame( array(), $fee_row['taxes'] );

		$this->assertNotEmpty( $refund['shipping'] );
		$ship_row = $refund['shipping'][0];
		$this->assertSame( 'Flat rate', $ship_row['label'] );
		$this->assertSame( 'flat_rate', $ship_row['method_id'] );
		$this->assertEqualsWithDelta( 3.00, (float) $ship_row['total'], 0.001 );
		$this->assertEqualsWithDelta( 3.00, (float) $ship_row['total_excl'], 0.001 );
		$this->assertEqualsWithDelta( 3.00, (float) $ship_row['total_incl'], 0.001 );
		$this->assertSame( array(), $ship_row['taxes'] );
	}

	/**
	 * Test refund Pro audit meta is surfaced when present on the refund.
	 */
	public function test_build_refunds_include_pro_audit_meta(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Refundable' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$line_item    = array_values( $order->get_items() )[0];
		$line_item_id = $line_item->get_id();

		$refund = wc_create_refund(
			array(
				'amount'     => '10.00',
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$line_item_id => array(
						'qty'          => 1,
						'refund_total' => 10.00,
						'refund_tax'   => array(),
					),
				),
			)
		);

		$refund->update_meta_data( '_pos_refund_destination', 'cash' );
		$refund->update_meta_data( '_pos_refund_mode', 'manual' );
		$refund->update_meta_data( '_pos_refund_gateway_id', 'cod' );
		$refund->save();

		WC()->payment_gateways = null;

		$payload     = $this->builder->build( wc_get_order( $order->get_id() ), 'live' );
		$out_refund  = $payload['refunds'][0];

		$this->assertSame( 'cash', $out_refund['destination'] );
		$this->assertSame( 'manual', $out_refund['processing_mode'] );
		$this->assertSame( 'cod', $out_refund['gateway_id'] );
		$this->assertSame( 'Cash on delivery', $out_refund['gateway_title'] );
	}

	/**
	 * Test that a persisted _pos_refund_gateway_title snapshot wins over
	 * the live gateway registry, so historical receipts keep their original
	 * audit value when a gateway is renamed or removed.
	 */
	public function test_build_refunds_prefer_persisted_gateway_title(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Refundable' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$line_item    = array_values( $order->get_items() )[0];
		$line_item_id = $line_item->get_id();

		$refund = wc_create_refund(
			array(
				'amount'     => '10.00',
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$line_item_id => array(
						'qty'          => 1,
						'refund_total' => 10.00,
						'refund_tax'   => array(),
					),
				),
			)
		);

		$refund->update_meta_data( '_pos_refund_gateway_id', 'cod' );
		$refund->update_meta_data( '_pos_refund_gateway_title', 'Pay on collection' );
		$refund->save();

		WC()->payment_gateways = null;

		$payload    = $this->builder->build( wc_get_order( $order->get_id() ), 'live' );
		$out_refund = $payload['refunds'][0];

		// Snapshot label is preserved even though the live registry would
		// resolve 'cod' to "Cash on delivery".
		$this->assertSame( 'cod', $out_refund['gateway_id'] );
		$this->assertSame( 'Pay on collection', $out_refund['gateway_title'] );
	}

	/**
	 * Test per-line qty_refunded / total_refunded and totals.refund_total are populated.
	 */
	public function test_build_includes_per_line_refund_info_and_refund_total(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Refundable' );
		$product->set_regular_price( '20.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 2 );
		$order->calculate_totals();
		$order->save();

		$line_item    = array_values( $order->get_items() )[0];
		$line_item_id = $line_item->get_id();

		wc_create_refund(
			array(
				'amount'     => '20.00',
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$line_item_id => array(
						'qty'          => 1,
						'refund_total' => 20.00,
						'refund_tax'   => array(),
					),
				),
			)
		);

		$payload = $this->builder->build( wc_get_order( $order->get_id() ), 'live' );

		$this->assertNotEmpty( $payload['lines'] );
		$line = $payload['lines'][0];
		$this->assertArrayHasKey( 'qty_refunded', $line );
		$this->assertArrayHasKey( 'total_refunded', $line );
		$this->assertEqualsWithDelta( 1.0, (float) $line['qty_refunded'], 0.001 );
		$this->assertEqualsWithDelta( 20.00, (float) $line['total_refunded'], 0.001 );

		$this->assertArrayHasKey( 'refund_total', $payload['totals'] );
		$this->assertEqualsWithDelta( 20.00, (float) $payload['totals']['refund_total'], 0.001 );

		// totals.net_total is the customer-facing balance after the refund
		// (order.total - refund_total, clamped to >= 0). The detailed-receipt
		// template renders this alongside the refund total when refunded > 0.
		$this->assertArrayHasKey( 'net_total', $payload['totals'] );
		$expected_net = max( 0.0, (float) $payload['totals']['total_incl'] - 20.00 );
		$this->assertEqualsWithDelta( $expected_net, (float) $payload['totals']['net_total'], 0.001 );
	}

	/**
	 * Test totals.net_total stays at 0 when no refunds exist. The detailed-receipt
	 * section guard `{{#totals.refund_total}}` is what hides the Refunded / Net rows
	 * in the no-refund case, so net_total is never displayed here.
	 */
	public function test_build_net_total_is_zero_when_no_refunds(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'No-refund product' );
		$product->set_regular_price( '12.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$payload = $this->builder->build( wc_get_order( $order->get_id() ), 'live' );

		$this->assertArrayHasKey( 'net_total', $payload['totals'] );
		$this->assertEqualsWithDelta( 0.0, (float) $payload['totals']['net_total'], 0.001 );
	}

	/**
	 * Test that a fully-refunded order produces net_total === 0 AND that the
	 * formatted display string is non-empty. The detailed-receipt template only
	 * gates the Refunded / Net rows on `refund_total`, so a full refund must
	 * still render "Net Total $0.00" rather than an empty span.
	 */
	public function test_build_full_refund_renders_zero_net_total_with_nonempty_display(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Fully refunded' );
		$product->set_regular_price( '15.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$line_item    = array_values( $order->get_items() )[0];
		$line_item_id = $line_item->get_id();

		wc_create_refund(
			array(
				'amount'     => '15.00',
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$line_item_id => array(
						'qty'          => 1,
						'refund_total' => 15.00,
						'refund_tax'   => array(),
					),
				),
			)
		);

		$payload   = $this->builder->build( wc_get_order( $order->get_id() ), 'live' );
		$currency  = $payload['order']['currency'] ?? 'USD';
		$formatted = Receipt_Data_Schema::format_money_fields( $payload, $currency );

		// Full refund: refund_total === total, so net_total clamps to 0.
		$this->assertEqualsWithDelta( 0.0, (float) $payload['totals']['net_total'], 0.001 );
		$this->assertGreaterThan( 0.0, (float) $payload['totals']['refund_total'] );

		// Critically, net_total_display must NOT be blank — otherwise the template
		// renders the "Net Total" label with an empty amount on full refunds.
		$this->assertArrayHasKey( 'net_total_display', $formatted['totals'] );
		$this->assertNotSame( '', $formatted['totals']['net_total_display'] );
	}
}
