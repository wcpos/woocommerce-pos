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

		foreach ( $phrases as $english => $translated ) {
			$this->assertMatchesRegularExpression( '/\{\{/', $english, "English phrase '$english' should contain Mustache placeholder." );
		}
	}

	/**
	 * Test translate_interpolated_phrases replaces known phrases.
	 */
	public function test_translate_interpolated_phrases_replaces_known_phrases(): void {
		$content = '<span>Paid via {{method_title}}</span>';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );

		// In English locale, should remain the same (English to English).
		$this->assertStringContainsString( '{{method_title}}', $result );
		$this->assertStringContainsString( 'Paid via', $result );
	}

	/**
	 * Test translate_interpolated_phrases preserves Mustache variables.
	 */
	public function test_translate_interpolated_phrases_preserves_mustache_variables(): void {
		$content = 'Tax ID: {{store.tax_id}} and Ref: {{reference}}';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );

		$this->assertStringContainsString( '{{store.tax_id}}', $result );
		$this->assertStringContainsString( '{{reference}}', $result );
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
	 * Test translate_interpolated_phrases handles multiple occurrences.
	 */
	public function test_translate_interpolated_phrases_handles_multiple_occurrences(): void {
		$content = '<span>Paid via {{method_title}}</span><span>Paid via {{method_title}}</span>';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );

		$this->assertSame( 2, substr_count( $result, '{{method_title}}' ) );
	}
}
