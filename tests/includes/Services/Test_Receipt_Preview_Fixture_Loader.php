<?php
/**
 * Tests for receipt preview JSON fixture loader.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader;
use WP_UnitTestCase;

/**
 * Test_Receipt_Preview_Fixture_Loader class.
 *
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader
 */
class Test_Receipt_Preview_Fixture_Loader extends WP_UnitTestCase {

	/**
	 * Base fixture applies controlled Coffee Monster data and logo asset.
	 *
	 * @covers ::build
	 */
	public function test_build_base_receipt_applies_controlled_store_data(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'base-receipt' );

		$this->assertSame( 'Coffee Monster', $data['store']['name'] );
		$this->assertStringContainsString( 'assets/img/template-gallery/preview-assets/coffee-monster-logo.svg', $data['store']['logo'] );
		$this->assertSame( 'POS-1234', $data['order']['number'] );
		$this->assertSame( 'House Espresso Beans', $data['lines'][0]['name'] );
		$this->assertSame( 'Grind', $data['lines'][0]['meta'][0]['key'] );
	}

	/**
	 * Invoice fixture represents an unpaid invoice.
	 *
	 * @covers ::build
	 */
	public function test_build_invoice_applies_unpaid_invoice_overrides(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'invoice' );

		$this->assertSame( 'INV-2048', $data['order']['number'] );
		$this->assertSame( 'pending', $data['order']['wc_status'] );
		$this->assertTrue( $data['order']['needs_payment'] );
		$this->assertSame( array(), $data['payments'] );
		$this->assertSame( 0, $data['totals']['paid_total'] );
		$this->assertSame( 'FAKE SAMPLE IBAN', $data['invoice']['bank_details']['iban'] );
	}

	/**
	 * RTL fixture uses RTL language content, not mirrored English content.
	 *
	 * @covers ::build
	 */
	public function test_build_rtl_profile_applies_rtl_locale_and_content(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'standard-receipt-rtl' );

		$this->assertSame( 'ar', $data['presentation_hints']['locale'] );
		$this->assertSame( 'rtl', $data['presentation_hints']['direction'] );
		$this->assertSame( 'وحش القهوة', $data['store']['name'] );
		$this->assertSame( 'ليلى أحمد', $data['customer']['name'] );
		$this->assertSame( 'حبوب إسبرسو منزلية', $data['lines'][0]['name'] );
		$this->assertSame( 'بطاقة', $data['payments'][0]['method_title'] );
		$this->assertSame( 170, $data['totals']['total'] );
		$this->assertSame( array(), $data['discounts'] );
		$this->assertSame( 'إيصال', $data['i18n']['receipt'] );
		$this->assertSame( 'الإجمالي', $data['i18n']['total'] );
	}

	/**
	 * Kitchen ticket fixture emphasizes prep notes and hides commerce sections.
	 *
	 * @covers ::build
	 */
	public function test_build_kitchen_ticket_applies_prep_focused_data(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'thermal-kitchen-ticket' );

		$this->assertSame( 'K-1042', $data['order']['number'] );
		$this->assertSame( 'Table 7', $data['customer']['name'] );
		$this->assertSame( 'Oat Milk Latte', $data['lines'][0]['name'] );
		$this->assertSame( 'Extra hot', $data['lines'][0]['meta'][1]['value'] );
		$this->assertSame( array(), $data['payments'] );
		$this->assertSame( array(), $data['shipping'] );
	}

	/**
	 * Quote fixture represents a pre-sale quote without inherited tendered totals.
	 *
	 * @covers ::build
	 */
	public function test_build_quote_applies_unpaid_quote_overrides(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'quote' );

		$this->assertSame( 'Q-3021', $data['order']['number'] );
		$this->assertSame( 'Quote', $data['order']['status_label'] );
		$this->assertSame( array(), $data['payments'] );
		$this->assertSame( 0, $data['totals']['paid_total'] );
		$this->assertSame( 0, $data['totals']['change_total'] );
		$this->assertSame( '2024-02-15', $data['quote']['valid_until'] );
	}

	/**
	 * Empty array overrides replace inherited associative sections.
	 *
	 * @covers ::deep_merge
	 */
	public function test_empty_array_override_replaces_associative_section(): void {
		$merge = new \ReflectionMethod( Receipt_Preview_Fixture_Loader::class, 'deep_merge' );
		$merge->setAccessible( true );

		$data = $merge->invoke(
			null,
			array(
				'invoice' => array(
					'bank_details' => array(
						'iban' => 'FAKE SAMPLE IBAN',
					),
				),
			),
			array(
				'invoice' => array(),
			)
		);

		$this->assertSame( array(), $data['invoice'] );
	}

	/**
	 * Missing fixture profiles fall back to base data instead of breaking previews.
	 *
	 * @covers ::build
	 */
	public function test_build_unknown_profile_falls_back_to_base_receipt(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'does-not-exist' );

		$this->assertSame( 'Coffee Monster', $data['store']['name'] );
		$this->assertSame( 'POS-1234', $data['order']['number'] );
	}
}
