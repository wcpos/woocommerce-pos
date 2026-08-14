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
	 * Invalid store decimal settings fall back to WooCommerce defaults.
	 */
	public function test_resolve_price_num_decimals_falls_back_for_invalid_store_values(): void {
		$original_num_decimals = get_option( 'woocommerce_price_num_decimals' );

		try {
			update_option( 'woocommerce_price_num_decimals', '2' );

			foreach ( array( 'invalid', -1, '-0.5', '' ) as $value ) {
				$store = new class( $value ) {
					private $value;

					public function __construct( $value ) {
						$this->value = $value;
					}

					public function get_price_number_of_decimals() {
						return $this->value;
					}
				};

				$this->assertSame( 2, ( new Receipt_Store_Resolver( $store ) )->resolve_price_num_decimals() );
			}
		} finally {
			if ( false === $original_num_decimals ) {
				delete_option( 'woocommerce_price_num_decimals' );
			} else {
				update_option( 'woocommerce_price_num_decimals', $original_num_decimals );
			}
		}
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

	/**
	 * A fully populated store supplies every store-section field itself.
	 */
	public function test_build_store_section_reads_every_field_from_a_populated_store(): void {
		$store = new class() {
			public function get_id(): int {
				return 42;
			}

			public function get_name(): string {
				return 'Downtown Store';
			}

			public function get_store_address(): string {
				return '123 Main St';
			}

			public function get_store_address_2(): string {
				return 'Suite 4';
			}

			public function get_store_city(): string {
				return 'San Francisco';
			}

			public function get_store_postcode(): string {
				return '94105';
			}

			public function get_store_country(): string {
				return 'US';
			}

			public function get_store_state(): string {
				return 'CA';
			}

			public function get_phone(): string {
				return '+1 555 0100';
			}

			public function get_email(): string {
				return 'shop@example.com';
			}

			public function get_tax_ids(): array {
				return array(
					array(
						'type'  => 'us_ein',
						'value' => '12-3456789',
					),
				);
			}

			public function get_opening_hours(): array {
				return array( '0' => array( '09:00', '17:00' ) );
			}

			public function get_opening_hours_notes(): string {
				return 'Closed public holidays';
			}

			public function get_personal_notes(): string {
				return 'See you soon';
			}

			public function get_policies_and_conditions(): string {
				return 'No refunds';
			}

			public function get_footer_imprint(): string {
				return 'Registered in CA';
			}
		};

		$section = ( new Receipt_Store_Resolver( $store ) )->build_store_section(
			array(
				'id'             => 999,
				'name'           => 'Fallback Store',
				'personal_notes' => 'Sample note',
			)
		);

		$this->assertSame( 42, $section['id'] );
		$this->assertSame( 'Downtown Store', $section['name'] );
		$this->assertSame( '123 Main St', $section['address']['address_1'] );
		$this->assertSame( 'Suite 4', $section['address']['address_2'] );
		$this->assertSame( 'San Francisco', $section['address']['city'] );
		$this->assertSame( 'CA', $section['address']['state'] );
		$this->assertSame( '94105', $section['address']['postcode'] );
		$this->assertSame( 'US', $section['address']['country'] );
		$this->assertContains( '123 Main St', $section['address_lines'] );
		$this->assertSame( '+1 555 0100', $section['phone'] );
		$this->assertSame( 'shop@example.com', $section['email'] );
		$this->assertCount( 1, $section['tax_ids'] );
		$this->assertSame( '12-3456789', $section['tax_ids'][0]['value'] );
		$this->assertNotEmpty( $section['tax_ids'][0]['label'] );
		$this->assertNotEmpty( $section['opening_hours'] );
		$this->assertNotEmpty( $section['opening_hours_vertical'] );
		$this->assertNotEmpty( $section['opening_hours_inline'] );
		$this->assertSame( 'Closed public holidays', $section['opening_hours_notes'] );
		$this->assertSame( 'See you soon', $section['personal_notes'] );
		$this->assertSame( 'No refunds', $section['policies_and_conditions'] );
		$this->assertSame( 'Registered in CA', $section['footer_imprint'] );
	}

	/**
	 * Partial store objects are tolerated: absent getters fall back per field.
	 */
	public function test_build_store_section_tolerates_partial_store_objects(): void {
		$store = new class() {
			public function get_name(): string {
				return 'Kiosk';
			}

			public function get_phone(): string {
				return '555-0199';
			}
		};

		$section = ( new Receipt_Store_Resolver( $store ) )->build_store_section();

		$this->assertSame( 'Kiosk', $section['name'] );
		$this->assertSame( '555-0199', $section['phone'] );
		$this->assertSame( 0, $section['id'] );
		$this->assertSame( '', $section['email'] );
		$this->assertSame( '', $section['address']['address_1'] );
		$this->assertSame( array(), $section['tax_ids'] );
		$this->assertNull( $section['opening_hours'] );
		$this->assertNull( $section['opening_hours_vertical'] );
		$this->assertNull( $section['opening_hours_inline'] );
		$this->assertNull( $section['opening_hours_notes'] );
		$this->assertNull( $section['personal_notes'] );
		$this->assertNull( $section['policies_and_conditions'] );
		$this->assertNull( $section['footer_imprint'] );
	}

	/**
	 * A bare object — the stand-in for a deleted store — takes every fallback.
	 */
	public function test_build_store_section_applies_fallbacks_for_bare_store_object(): void {
		$section = ( new Receipt_Store_Resolver( new \stdClass() ) )->build_store_section(
			array(
				'id'                      => 987654,
				'name'                    => 'Store #987654',
				'opening_hours'           => array( '0' => array( '09:00', '17:00' ) ),
				'opening_hours_notes'     => 'Sample notes',
				'personal_notes'          => 'Sample personal notes',
				'policies_and_conditions' => 'Sample policies',
				'footer_imprint'          => 'Sample imprint',
			)
		);

		$this->assertSame( 987654, $section['id'] );
		$this->assertSame( 'Store #987654', $section['name'] );
		$this->assertSame( '', $section['address']['address_1'] );
		$this->assertSame( array(), $section['address_lines'] );
		$this->assertSame( array(), $section['tax_ids'] );
		$this->assertSame( '', $section['phone'] );
		$this->assertNotEmpty( $section['opening_hours'] );
		$this->assertSame( 'Sample notes', $section['opening_hours_notes'] );
		$this->assertSame( 'Sample personal notes', $section['personal_notes'] );
		$this->assertSame( 'Sample policies', $section['policies_and_conditions'] );
		$this->assertSame( 'Sample imprint', $section['footer_imprint'] );
	}

	/**
	 * A blank store name falls back to the site title, not to an empty string.
	 */
	public function test_build_store_section_falls_back_to_site_title_for_blank_name(): void {
		$store = new class() {
			public function get_name(): string {
				return '';
			}
		};

		$section = ( new Receipt_Store_Resolver( $store ) )->build_store_section();

		$this->assertSame( get_bloginfo( 'name' ), $section['name'] );
	}

	/**
	 * Legacy free-text opening hours win over the structured sample fallback.
	 */
	public function test_build_store_section_preserves_legacy_string_opening_hours(): void {
		$store = new class() {
			public function get_opening_hours(): string {
				return 'Mon-Fri 09:00-17:00';
			}
		};

		$section = ( new Receipt_Store_Resolver( $store ) )->build_store_section(
			array(
				'opening_hours' => array( '0' => array( '09:00', '17:00' ) ),
			)
		);

		$this->assertSame( 'Mon-Fri 09:00-17:00', $section['opening_hours'] );
		$this->assertNull( $section['opening_hours_vertical'] );
		$this->assertNull( $section['opening_hours_inline'] );
	}
}
