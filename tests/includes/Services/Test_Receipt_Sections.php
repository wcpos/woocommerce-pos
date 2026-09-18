<?php
/**
 * Tests for receipt section shapes and aggregate rules.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Sections;
use WP_UnitTestCase;

/**
 * Receipt section contract tests.
 */
class Test_Receipt_Sections extends WP_UnitTestCase {
	/**
	 * Priced input with a two-unit sale and a separate discount.
	 *
	 * @return array
	 */
	private function priced_line(): array {
		return array(
			'key' => '1',
			'sku' => 'SKU',
			'name' => 'Widget',
			'qty' => 2.0,
			'qty_refunded' => 0.0,
			'total_refunded' => 0.0,
			'taxes' => array(),
			'meta' => array(),
			'attributes' => array(),
			'unit_subtotal' => array(
				'incl' => 12.0,
				'excl' => 10.0,
			),
			'unit_price' => array(
				'incl' => 10.8,
				'excl' => 9.0,
			),
			'line_subtotal' => array(
				'incl' => 24.0,
				'excl' => 20.0,
			),
			'discounts' => array(
				'incl' => 2.4,
				'excl' => 2.0,
			),
			'line_total' => array(
				'incl' => 21.6,
				'excl' => 18.0,
			),
			'regular_price' => array(
				'incl' => 15.0,
				'excl' => 12.5,
			),
			'selling_price' => array(
				'incl' => 12.0,
				'excl' => 10.0,
			),
			'unit_savings' => array(
				'incl' => 3.0,
				'excl' => 2.5,
			),
			'line_regular_total' => array(
				'incl' => 30.0,
				'excl' => 25.0,
			),
			'line_selling_total' => array(
				'incl' => 24.0,
				'excl' => 20.0,
			),
			'line_savings' => array(
				'incl' => 6.0,
				'excl' => 5.0,
			),
			'savings_in_discounts' => false,
		);
	}

	/**
	 * Order-level input independent of the row shaper.
	 *
	 * @return array
	 */
	private function amounts(): array {
		return array(
			'discount_total' => array(
				'incl' => 2.4,
				'excl' => 2.0,
			),
			'tax_total' => 3.6,
			'total' => 21.6,
			'paid_total' => 21.6,
			'change_total' => 3.4,
			'refund_total' => 0.0,
		);
	}

	/** Bare keys must select the requested basis without changing explicit bases. */
	public function test_line_display_basis_selects_all_bare_amounts(): void {
		$input = $this->priced_line();
		foreach ( array( true, false ) as $display_incl ) {
			$line = Receipt_Sections::line( $input, $display_incl );
			foreach ( array( 'unit_subtotal', 'unit_price', 'line_subtotal', 'discounts', 'line_total', 'regular_price', 'selling_price', 'unit_savings', 'line_regular_total', 'line_selling_total', 'line_savings' ) as $key ) {
				$this->assertSame( $input[ $key ][ $display_incl ? 'incl' : 'excl' ], $line[ $key ] );
				$this->assertSame( $input[ $key ]['incl'], $line[ $key . '_incl' ] );
				$this->assertSame( $input[ $key ]['excl'], $line[ $key . '_excl' ] );
			}
		}
	}

	/** Missing recorded regular prices must not become zero. */
	public function test_line_null_regular_prices_remain_null(): void {
		$input = $this->priced_line();
		$input['regular_price'] = array(
			'incl' => null,
			'excl' => null,
		);
		foreach ( array( true, false ) as $display_incl ) {
			$line = Receipt_Sections::line( $input, $display_incl );
			$this->assertNull( $line['regular_price'] );
			$this->assertNull( $line['regular_price_incl'] );
			$this->assertNull( $line['regular_price_excl'] );
		}
	}

	/** The shared shaper keeps the live row's complete key order. */
	public function test_line_contract_keys_preserve_live_order(): void {
		$line = Receipt_Sections::line( $this->priced_line(), true );
		$this->assertSame(
			array(
				'key',
				'sku',
				'name',
				'qty',
				'qty_refunded',
				'unit_subtotal',
				'unit_subtotal_incl',
				'unit_subtotal_excl',
				'unit_price',
				'unit_price_incl',
				'unit_price_excl',
				'line_subtotal',
				'line_subtotal_incl',
				'line_subtotal_excl',
				'discounts',
				'discounts_incl',
				'discounts_excl',
				'line_total',
				'line_total_incl',
				'line_total_excl',
				'total_refunded',
				'taxes',
				'meta',
				'attributes',
				'regular_price',
				'regular_price_incl',
				'regular_price_excl',
				'selling_price',
				'selling_price_incl',
				'selling_price_excl',
				'unit_savings',
				'unit_savings_incl',
				'unit_savings_excl',
				'line_regular_total',
				'line_regular_total_incl',
				'line_regular_total_excl',
				'line_selling_total',
				'line_selling_total_incl',
				'line_selling_total_excl',
				'line_savings',
				'line_savings_incl',
				'line_savings_excl',
				'savings_in_discounts',
			),
			array_keys( $line )
		);
	}

	/** Subtotals and quantities are aggregated, not supplied by callers. */
	public function test_totals_multiple_lines_sum_subtotals_and_quantities(): void {
		$line = Receipt_Sections::line( $this->priced_line(), true );
		$totals = Receipt_Sections::totals( array( $line, $line ), $this->amounts(), true );
		$this->assertSame( 48.0, $totals['subtotal'] );
		$this->assertSame( 48.0, $totals['subtotal_incl'] );
		$this->assertSame( 40.0, $totals['subtotal_excl'] );
		$this->assertSame( 4.0, $totals['total_qty'] );
		$this->assertSame( 2, $totals['line_count'] );
		$this->assertSame( 14.4, $totals['total_saved'] );
		$this->assertSame( 12.0, $totals['total_saved_excl'] );
		$this->assertTrue( $totals['total_saved_complete'] );
	}

	/** Any unknown savings invalidate the aggregate for that basis. */
	public function test_totals_unknown_savings_mark_totals_incomplete(): void {
		$known = Receipt_Sections::line( $this->priced_line(), true );
		$unknown = $known;
		$unknown['line_savings_incl'] = null;
		$unknown['line_savings_excl'] = 'unknown';
		foreach ( array( true, false ) as $display_incl ) {
			$totals = Receipt_Sections::totals( array( $known, $unknown ), $this->amounts(), $display_incl );
			$this->assertNull( $totals['total_saved'] );
			$this->assertNull( $totals['total_saved_incl'] );
			$this->assertNull( $totals['total_saved_excl'] );
			$this->assertNull( $totals['sale_savings_total'] );
			$this->assertFalse( $totals['total_saved_complete'] );
		}
	}

	/** Legacy savings already in discounts must not be counted twice. */
	public function test_totals_legacy_savings_exclude_overlap_only_from_total_saved(): void {
		$line = Receipt_Sections::line( $this->priced_line(), false );
		$line['savings_in_discounts'] = true;
		$totals = Receipt_Sections::totals( array( $line ), $this->amounts(), false );
		$this->assertSame( 5.0, $totals['sale_savings_total'] );
		$this->assertSame( 6.0, $totals['sale_savings_total_incl'] );
		$this->assertSame( 2.0, $totals['total_saved'] );
		$this->assertSame( 2.4, $totals['total_saved_incl'] );
		$this->assertTrue( $totals['total_saved_complete'] );
	}

	/** A subtotal inconsistent with selling prices makes savings uncertain. */
	public function test_totals_mismatched_subtotal_marks_savings_incomplete(): void {
		$line = Receipt_Sections::line( $this->priced_line(), true );
		$line['line_subtotal_incl'] = 30.0;
		$totals = Receipt_Sections::totals( array( $line ), $this->amounts(), true );
		$this->assertNull( $totals['total_saved_incl'] );
		$this->assertFalse( $totals['total_saved_complete'] );
		$this->assertSame( 7.0, $totals['total_saved_excl'] );
	}

	/** Net total is hidden until a refund and never goes below zero. */
	public function test_totals_refunds_compute_guarded_net_total(): void {
		foreach ( array( array( 0.0, 0.0 ), array( 5.0, 16.6 ), array( 25.0, 0.0 ) ) as $case ) {
			$amounts = $this->amounts();
			$amounts['refund_total'] = $case[0];
			$totals = Receipt_Sections::totals( array(), $amounts, true );
			$this->assertSame( $case[1], $totals['net_total'] );
		}
	}

	/** Unknown rates and taxable bases remain null rather than inventing values. */
	public function test_tax_summary_entry_unknown_rate_and_base_stay_null(): void {
		$this->assertSame(
			array(
				'code' => '1',
				'rate' => null,
				'label' => 'Tax',
				'compound' => false,
				'taxable_amount_excl' => null,
				'tax_amount' => 2.0,
				'taxable_amount_incl' => null,
			),
			Receipt_Sections::tax_summary_entry( '1', 0.0, 'Tax', false, null, 2.0 )
		);
		$entry = Receipt_Sections::tax_summary_entry( '1', 20.0, 'Tax', true, 10.0, 2.0 );
		$this->assertSame( 20.0, $entry['rate'] );
		$this->assertSame( 12.0, $entry['taxable_amount_incl'] );
	}

	/** Explicit labels win; known types and unknown types use scoped labels. */
	public function test_label_tax_ids_precedence_resolves_explicit_typed_and_fallback(): void {
		$ids = array(
			array(
				'type' => 'eu_vat',
				'label' => 'Custom',
			),
			array( 'type' => 'eu_vat' ),
			array( 'type' => 'unrecognised' ),
		);
		foreach ( array( 'customer', 'store' ) as $scope ) {
			$labelled = Receipt_Sections::label_tax_ids( $ids, $scope, 'en_US' );
			$this->assertSame( array( 'Custom', 'VAT ID', 'Tax ID' ), array_column( $labelled, 'label' ) );
		}
	}

	/** Real variations resolve taxonomy terms and custom attributes, skipping blanks. */
	public function test_variation_attribute_pairs_resolve_taxonomy_and_custom_values(): void {
		$attribute_id = wc_create_attribute(
			array(
				'name' => 'Receipt color',
				'slug' => 'receipt_color',
				'type' => 'select',
			)
		);
		register_taxonomy( 'pa_receipt_color', 'product', array( 'label' => 'Receipt color' ) );
		$term = wp_insert_term( 'Ocean Blue', 'pa_receipt_color', array( 'slug' => 'ocean-blue' ) );
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable widget' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes(
			array(
				'pa_receipt_color' => 'ocean-blue',
				'size' => 'Large',
				'empty' => '',
			)
		);
		$variation->save();
		try {
			$this->assertSame(
				array(
					array(
						'key' => 'Receipt color',
						'value' => 'Ocean Blue',
					),
					array(
						'key' => 'size',
						'value' => 'Large',
					),
				),
				Receipt_Sections::variation_attribute_pairs( $variation )
			);
		} finally {
			$variation->delete( true );
			$parent->delete( true );
			wp_delete_term( $term['term_id'], 'pa_receipt_color' );
			if ( taxonomy_exists( 'pa_receipt_color' ) ) {
				unregister_taxonomy( 'pa_receipt_color' );
			}
			wc_delete_attribute( (int) $attribute_id );
		}
	}
}
