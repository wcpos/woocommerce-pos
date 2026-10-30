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
	 * Closure template previews use the requested store identity.
	 */
	public function test_for_template_closure_uses_the_given_store(): void {
		// Arrange.
		$store = new class() {
			/**
			 * Return the preview store ID.
			 *
			 * @return int Store ID.
			 */
			public function get_id(): int {
				return 7;
			}
		};
		$filter = static function ( $resolved_store, $the_store, $args ) use ( $store ) {
			return 7 === $the_store ? $store : $resolved_store;
		};
		add_filter( 'wcpos_get_store', $filter, 10, 3 );

		try {
			// Act.
			$data = ( new Receipt_Preview_Fixture_Loader() )->for_template(
				array(
					'type' => 'closure',
				),
				$store
			);

			// Assert.
			$this->assertSame( 7, $data['store']['id'] );
		} finally {
			remove_filter( 'wcpos_get_store', $filter, 10 );
		}
	}

	/**
	 * Receipt template previews use their gallery profile.
	 */
	public function test_for_template_uses_the_template_preview_profile(): void {
		// Arrange.
		$template = array(
			'type'         => 'receipt',
			'preview_data' => 'invoice',
		);

		// Act.
		$data = ( new Receipt_Preview_Fixture_Loader() )->for_template( $template );

		// Assert.
		$this->assertSame( 'INV-2048', $data['order']['number'] );
	}

	/**
	 * Report template previews use the report key instead of their gallery profile.
	 */
	public function test_for_template_report_uses_report_key_and_ignores_profile(): void {
		// Arrange.
		$template = array(
			'type'         => 'report',
			'preview_data' => 'invoice',
		);

		// Act.
		$data = ( new Receipt_Preview_Fixture_Loader() )->for_template( $template, null, 'cash-movements' );

		// Assert.
		$this->assertSame( 'cash-movements', $data['report']['key'] );
	}

	/** Closure previews carry every optional section without sale identity. */
	public function test_closure_fixture_contains_full_document(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'closure' );
		$this->assertSame( 'closure', $data['fiscal']['document_type'] );
		$this->assertFalse( $data['fiscal']['is_sale_document'] );
		$this->assertTrue( $data['fiscal']['is_closure_document'] );
		foreach ( array( 'payment_methods', 'tax_rates', 'opening_float', 'movements', 'transaction_count', 'refund_count', 'cashiers', 'discounts', 'items_sold', 'average_sale', 'drawer_openings', 'variance_note', 'denominations' ) as $section ) {
			$this->assertNotEmpty( $data['closure']['breakdowns'][ $section ] );
		}
		$this->assertSame( '-2.0000', $data['closure']['variance']['cash'] );
	}

	/**
	 * Base fixture applies controlled Coffee Monster data and logo asset.
	 *
	 * @covers ::build
	 */
	public function test_build_base_receipt_applies_controlled_store_data(): void {
		$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'base-receipt' );

		$this->assertSame( 'Coffee Monster', $data['store']['name'] );
		$this->assertStringContainsString( 'assets/img/template-gallery/preview-assets/coffee-monster.png', $data['store']['logo'] );
		$this->assertSame( 'POS-1234', $data['order']['number'] );
		$this->assertSame( 'House Espresso Beans', $data['lines'][0]['name'] );
		$this->assertSame( 'Grind', $data['lines'][0]['meta'][0]['key'] );
		$this->assertSame( 7.25, $data['totals']['refund_total'] );
		$this->assertSame( 52.72, $data['totals']['net_total'] );
		$this->assertSame( 'Changed mind about the mug.', $data['refunds'][0]['reason'] );
		$this->assertSame( 'Black Ceramic Mug', $data['refunds'][0]['lines'][0]['name'] );
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
		$this->assertSame( 10, $data['totals']['sale_savings_total'] );
		$this->assertSame( 10, $data['totals']['total_saved'] );
		$this->assertSame( array(), $data['discounts'] );
		$this->assertSame( 'إجمالي التوفير', $data['i18n']['total_saved'] );
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

	/**
	 * The receipt data filter runs once, after the fixture overrides, so a key a
	 * plugin adds to a row survives the fixture replacing that row list.
	 *
	 * @covers ::build
	 */
	public function test_build_applies_receipt_data_filter_once_after_overrides(): void {
		$calls  = 0;
		$filter = static function ( array $data, \WC_Order $order, string $mode ) use ( &$calls ): array {
			++$calls;
			$data['discounts'][0]['gift_card'] = true;
			$data['seen_store']                = $data['store']['name'];

			return $data;
		};

		add_filter( 'wcpos_receipt_data', $filter, 10, 3 );
		try {
			$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'base-receipt' );
		} finally {
			remove_filter( 'wcpos_receipt_data', $filter, 10 );
		}

		$this->assertSame( 1, $calls );
		$this->assertTrue( $data['discounts'][0]['gift_card'] );
		$this->assertSame( 'Coffee Monster', $data['seen_store'] );
	}
}
