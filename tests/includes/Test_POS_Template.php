<?php
/**
 * Tests for the POS page template.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Test_POS_Template class.
 */
class Test_POS_Template extends WP_UnitTestCase {
	/**
	 * The POS template should advertise a browser favicon and only reference
	 * plugin assets that ship with WCPOS.
	 */
	public function test_pos_template_icon_urls_reference_existing_plugin_assets(): void {
		// Arrange.
		ob_start();

		// Act.
		include \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/pos.php';
		$html = ob_get_clean();

		// Assert.
		$this->assertIsString( $html );
		$this->assertEquals(
			1,
			preg_match( '/<link\b[^>]*\brel="icon"[^>]*>/i', $html ),
			'The POS template must include a browser favicon link.'
		);

		preg_match_all(
			'/(?:href|content)="(' . preg_quote( \WCPOS\WooCommercePOS\PLUGIN_URL, '/' ) . 'assets\/[^\"]+)"/i',
			$html,
			$asset_urls
		);

		$this->assertNotEmpty( $asset_urls[1], 'The POS template must reference plugin assets.' );
		foreach ( $asset_urls[1] as $asset_url ) {
			$asset_path = \WCPOS\WooCommercePOS\PLUGIN_PATH . substr( $asset_url, strlen( \WCPOS\WooCommercePOS\PLUGIN_URL ) );
			$this->assertFileExists( $asset_path, 'Missing POS template asset: ' . $asset_url );
		}
	}
}
