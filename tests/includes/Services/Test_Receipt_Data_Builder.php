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
	 * Test canonical payload includes required top-level keys.
	 */
	public function test_build_includes_required_top_level_keys(): void {
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		foreach ( Receipt_Data_Schema::REQUIRED_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $payload );
		}

		$this->assertEquals( Receipt_Data_Schema::VERSION, $payload['meta']['schema_version'] );
		$this->assertEquals( 'live', $payload['receipt']['mode'] );
		$this->assertArrayNotHasKey( 'mode', $payload['meta'] );
		$this->assertArrayHasKey( 'wc_status', $payload['meta'] );
		$this->assertArrayHasKey( 'created_via', $payload['meta'] );
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
	 * Test semantic receipt and order sections include rich date data.
	 */
	public function test_build_includes_semantic_receipt_and_order_date_sections(): void {
		$order   = wc_get_order( OrderHelper::create_order() );
		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'receipt', $payload );
		$this->assertArrayHasKey( 'order', $payload );
		$this->assertSame( 'live', $payload['receipt']['mode'] );
		$this->assertSame( (string) $order->get_order_number(), $payload['order']['number'] );
		$this->assertSame( (string) $order->get_currency(), $payload['order']['currency'] );
		$this->assertSame( (string) $order->get_customer_note(), $payload['order']['customer_note'] );

		foreach ( array( 'created', 'paid', 'completed' ) as $field ) {
			$this->assertArrayHasKey( $field, $payload['order'] );
			$this->assertArrayHasKey( 'datetime_full', $payload['order'][ $field ] );
			$this->assertArrayHasKey( 'date_ymd', $payload['order'][ $field ] );
			$this->assertArrayHasKey( 'weekday_short', $payload['order'][ $field ] );
		}

		$this->assertNotSame( '', $payload['order']['created']['datetime'] );

		$this->assertArrayHasKey( 'printed', $payload['receipt'] );
		$this->assertArrayHasKey( 'datetime', $payload['receipt']['printed'] );
		$this->assertArrayHasKey( 'datetime_full', $payload['receipt']['printed'] );
		$this->assertArrayHasKey( 'date_mdy', $payload['receipt']['printed'] );
		$this->assertArrayHasKey( 'month_long', $payload['receipt']['printed'] );
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
	 * Test store block includes tax_ids array sourced from WC option.
	 */
	public function test_store_block_includes_tax_ids_array_from_wc_option(): void {
		update_option( 'woocommerce_store_tax_number', 'DE123456789' );
		update_option( 'woocommerce_default_country', 'DE:BY' );
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		$this->assertArrayHasKey( 'tax_ids', $payload['store'] );
		$this->assertCount( 1, $payload['store']['tax_ids'] );
		$this->assertSame( 'eu_vat', $payload['store']['tax_ids'][0]['type'] );
		$this->assertSame( 'DE123456789', $payload['store']['tax_ids'][0]['value'] );
		$this->assertSame( 'DE', $payload['store']['tax_ids'][0]['country'] );

		// Back-compat scalar still emitted.
		$this->assertSame( 'DE123456789', $payload['store']['tax_id'] );
	}

	/**
	 * Test store block emits empty tax_ids when WC option is blank.
	 */
	public function test_store_block_emits_empty_tax_ids_when_wc_option_blank(): void {
		update_option( 'woocommerce_store_tax_number', '' );
		$order   = OrderHelper::create_order();
		$payload = $this->builder->build( $order, 'live' );

		$this->assertSame( array(), $payload['store']['tax_ids'] );
		$this->assertSame( '', $payload['store']['tax_id'] );
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
		$coupon_a->save();

		$coupon_b = new \WC_Coupon();
		$coupon_b->set_code( 'CODE_B' );
		$coupon_b->set_amount( 10 );
		$coupon_b->set_discount_type( 'fixed_cart' );
		$coupon_b->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->apply_coupon( 'CODE_A' );
		$order->apply_coupon( 'CODE_B' );
		$order->calculate_totals();
		$order->save();

		$payload = $this->builder->build( $order, 'live' );

		$this->assertCount( 2, $payload['discounts'] );
		$codes = array_column( $payload['discounts'], 'code' );
		$this->assertContains( wc_format_coupon_code( 'CODE_A' ), $codes );
		$this->assertContains( wc_format_coupon_code( 'CODE_B' ), $codes );
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
	}
}
