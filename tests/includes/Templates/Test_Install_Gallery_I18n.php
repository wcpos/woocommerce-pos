<?php
/**
 * Tests for gallery template i18n translation at install time.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;
use WP_UnitTestCase;

/**
 * Test_Install_Gallery_I18n class.
 */
class Test_Install_Gallery_I18n extends WP_UnitTestCase {

	/**
	 * Test that known interpolated phrases are replaced.
	 *
	 * @return void
	 */
	public function test_translate_interpolated_phrases_replaces_known_phrases(): void {
		$content = '<span>Paid via {{method_title}}</span>';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );
		$this->assertStringContainsString( '{{method_title}}', $result );
		$this->assertStringContainsString( 'Paid via', $result );
	}

	/**
	 * Test that Mustache variables are preserved after translation.
	 *
	 * @return void
	 */
	public function test_translate_interpolated_phrases_preserves_mustache_variables(): void {
		$content = 'Tax ID: {{store.tax_id}} and Ref: {{reference}}';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );
		$this->assertStringContainsString( '{{store.tax_id}}', $result );
		$this->assertStringContainsString( '{{reference}}', $result );
	}

	/**
	 * Test that unknown phrases are left unchanged.
	 *
	 * @return void
	 */
	public function test_translate_interpolated_phrases_ignores_unknown_phrases(): void {
		$content = 'This is not a known phrase {{foo}}';
		$result  = Receipt_I18n_Labels::translate_interpolated_phrases( $content );
		$this->assertSame( $content, $result );
	}
}
