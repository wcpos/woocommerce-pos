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
}
