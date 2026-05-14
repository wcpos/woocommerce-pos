<?php
/**
 * Tests for shared receipt store resolver.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Store_Resolver;
use WP_UnitTestCase;

/**
 * Test_Receipt_Store_Resolver class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Store_Resolver extends WP_UnitTestCase {
	/**
	 * Tax section exposes branchable booleans derived from store settings.
	 */
	public function test_build_tax_section_returns_branchable_tax_modes(): void {
		$store = new class() {
			public function get_tax_display_cart(): string {
				return 'incl';
			}

			public function get_calc_taxes(): string {
				return 'yes';
			}

			public function get_tax_total_display(): string {
				return 'single';
			}
		};

		$tax = ( new Receipt_Store_Resolver( $store ) )->build_tax_section();

		$this->assertSame( 'incl', $tax['display'] );
		$this->assertTrue( $tax['display_incl'] );
		$this->assertFalse( $tax['display_excl'] );
		$this->assertSame( 'single', $tax['breakdown'] );
		$this->assertTrue( $tax['breakdown_single'] );
		$this->assertFalse( $tax['breakdown_hidden'] );
		$this->assertFalse( $tax['breakdown_itemized'] );
	}

	/**
	 * Disabled taxes force a hidden breakdown while preserving display mode.
	 */
	public function test_build_tax_section_hides_breakdown_when_taxes_disabled(): void {
		$store = new class() {
			public function get_tax_display_cart(): string {
				return 'excl';
			}

			public function get_calc_taxes(): string {
				return 'no';
			}

			public function get_tax_total_display(): string {
				return 'single';
			}
		};

		$tax = ( new Receipt_Store_Resolver( $store ) )->build_tax_section();

		$this->assertSame( 'excl', $tax['display'] );
		$this->assertTrue( $tax['display_excl'] );
		$this->assertSame( 'hidden', $tax['breakdown'] );
		$this->assertTrue( $tax['breakdown_hidden'] );
	}

	/**
	 * Presentation hints expose formatting inputs only, not template mode signals.
	 */
	public function test_build_presentation_hints_excludes_barcode_and_tax_mode_signals(): void {
		$store = new class() {
			public function get_prices_include_tax(): string {
				return 'yes';
			}

			public function get_tax_round_at_subtotal(): string {
				return 'yes';
			}

			public function get_locale(): string {
				return 'fr_FR';
			}

			public function get_timezone(): string {
				return 'Europe/Paris';
			}

			public function get_currency_position(): string {
				return 'right_space';
			}

			public function get_price_thousand_separator(): string {
				return ' ';
			}

			public function get_price_decimal_separator(): string {
				return ',';
			}

			public function get_price_number_of_decimals(): int {
				return 3;
			}

			public function get_price_display_suffix(): string {
				return 'TTC';
			}
		};

		$hints = ( new Receipt_Store_Resolver( $store ) )->build_presentation_hints( 'EUR' );

		$this->assertArrayNotHasKey( 'display_tax', $hints );
		$this->assertArrayNotHasKey( 'order_barcode_type', $hints );
		$this->assertTrue( $hints['prices_entered_with_tax'] );
		$this->assertSame( 'yes', $hints['rounding_mode'] );
		$this->assertSame( 'fr_FR', $hints['locale'] );
		$this->assertSame( 'Europe/Paris', $hints['timezone'] );
		$this->assertSame( 'right_space', $hints['currency_position'] );
		$this->assertSame( ' ', $hints['price_thousand_separator'] );
		$this->assertSame( ',', $hints['price_decimal_separator'] );
		$this->assertSame( 3, $hints['price_num_decimals'] );
		$this->assertSame( 'TTC', $hints['price_display_suffix'] );
	}

	/**
	 * Address lines use WooCommerce country-specific formatting.
	 */
	public function test_compose_address_lines_uses_woocommerce_formatting(): void {
		$lines = Receipt_Store_Resolver::compose_address_lines(
			array(
				'address_1' => '123 Main St',
				'address_2' => 'Suite 4',
				'city'      => 'San Francisco',
				'state'     => 'CA',
				'postcode'  => '94105',
				'country'   => 'US',
			)
		);

		$this->assertContains( '123 Main St', $lines );
		$this->assertContains( 'Suite 4', $lines );
		$this->assertContains( 'San Francisco, CA 94105', $lines );
	}
}
