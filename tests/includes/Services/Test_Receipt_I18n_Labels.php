<?php
/**
 * Tests for Receipt_I18n_Labels.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;
use WP_UnitTestCase;

/**
 * Test_Receipt_I18n_Labels class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_I18n_Labels extends WP_UnitTestCase {

	/**
	 * Test get_labels returns array with expected keys.
	 */
	public function test_get_labels_returns_array_with_expected_keys(): void {
		$labels = Receipt_I18n_Labels::get_labels();

		$this->assertIsArray( $labels );
		$this->assertArrayHasKey( 'order', $labels );
		$this->assertArrayHasKey( 'date', $labels );
		$this->assertArrayHasKey( 'cashier', $labels );
		$this->assertArrayHasKey( 'customer', $labels );
		$this->assertArrayHasKey( 'subtotal', $labels );
		$this->assertArrayHasKey( 'total', $labels );
		$this->assertArrayHasKey( 'tendered', $labels );
		$this->assertArrayHasKey( 'change', $labels );
	}

	/**
	 * Test get_labels values are all strings.
	 */
	public function test_get_labels_values_are_strings(): void {
		$labels = Receipt_I18n_Labels::get_labels();

		foreach ( $labels as $key => $value ) {
			$this->assertIsString( $value, "Label '$key' should be a string." );
		}
	}

	/**
	 * Test get_interpolated_phrases returns a non-empty array.
	 */
	public function test_get_interpolated_phrases_returns_array(): void {
		$phrases = Receipt_I18n_Labels::get_interpolated_phrases();

		$this->assertIsArray( $phrases );
		$this->assertNotEmpty( $phrases );
	}

	/**
	 * Test interpolated phrase keys contain Mustache placeholders.
	 */
	public function test_interpolated_phrases_contain_mustache_placeholders(): void {
		$phrases = Receipt_I18n_Labels::get_interpolated_phrases();

		foreach ( array_keys( $phrases ) as $english ) {
			$this->assertMatchesRegularExpression( '/\{\{/', $english, "English phrase '$english' should contain Mustache placeholder." );
		}
	}

	/**
	 * Test translate_interpolated_phrases replaces known phrases.
	 */
	public function test_translate_interpolated_phrases_replaces_known_phrases(): void {
		// Force a non-English translation so we can verify replacement actually occurs.
		$filter = function ( $translation, $text ) {
			if ( 'Paid via %s' === $text ) {
				return 'Payé via %s';
			}
			return $translation;
		};
		add_filter( 'gettext', $filter, 10, 2 );

		$content = '<span>Paid via {{method_title}}</span>';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );

		$this->assertStringContainsString( '{{method_title}}', $result, 'Mustache placeholder must be preserved.' );
		$this->assertStringContainsString( 'Payé via', $result, 'Translation must replace English phrase.' );
		$this->assertStringNotContainsString( 'Paid via', $result, 'Original English phrase must be replaced.' );

		remove_filter( 'gettext', $filter, 10 );
	}

	/**
	 * Test translate_interpolated_phrases preserves Mustache variables.
	 */
	public function test_translate_interpolated_phrases_preserves_mustache_variables(): void {
		$content = 'Tax ID: {{store.tax_id}} and Ref: {{transaction_id}}';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );

		$this->assertStringContainsString( '{{store.tax_id}}', $result );
		$this->assertStringContainsString( '{{transaction_id}}', $result );
	}

	/**
	 * Test translate_interpolated_phrases ignores unknown phrases.
	 */
	public function test_translate_interpolated_phrases_ignores_unknown_phrases(): void {
		$content = 'This is not a known phrase {{foo}}';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );

		$this->assertSame( $content, $result );
	}

	/**
	 * Test get_labels includes fiscal label keys.
	 */
	public function test_get_labels_includes_fiscal_keys(): void {
		$labels = Receipt_I18n_Labels::get_labels();

		$this->assertArrayHasKey( 'signature', $labels );
		$this->assertArrayHasKey( 'document_type', $labels );
		$this->assertArrayHasKey( 'copy', $labels );
		$this->assertArrayHasKey( 'copy_number', $labels );
	}

	/**
	 * Test get_labels includes short receipt table header keys.
	 */
	public function test_get_labels_includes_short_receipt_table_header_keys(): void {
		$labels = Receipt_I18n_Labels::get_labels();

		$expected = array(
			'item_short'           => 'Item',
			'sku_short'            => 'SKU',
			'qty_short'            => 'Qty',
			'unit_excl_short'      => 'Unit excl.',
			'tax_rate_short'       => 'Tax %',
			'tax_amount_short'     => 'Tax',
			'total_incl_tax_short' => 'Total incl.',
			'taxable_excl_short'   => 'Taxable excl.',
			'taxable_incl_short'   => 'Taxable incl.',
		);

		foreach ( $expected as $key => $value ) {
			$this->assertArrayHasKey( $key, $labels );
			$this->assertSame( $value, $labels[ $key ] );
		}
	}

	/**
	 * Test translate_interpolated_phrases handles multiple occurrences.
	 */
	public function test_translate_interpolated_phrases_handles_multiple_occurrences(): void {
		$filter = function ( $translation, $text ) {
			if ( 'Paid via %s' === $text ) {
				return 'Payé via %s';
			}
			return $translation;
		};
		add_filter( 'gettext', $filter, 10, 2 );

		$content = '<span>Paid via {{method_title}}</span><span>Paid via {{method_title}}</span>';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );

		$this->assertSame( 2, substr_count( $result, '{{method_title}}' ), 'Both Mustache placeholders must be preserved.' );
		$this->assertSame( 2, substr_count( $result, 'Payé via' ), 'Both occurrences must be translated.' );

		remove_filter( 'gettext', $filter, 10 );
	}
}
