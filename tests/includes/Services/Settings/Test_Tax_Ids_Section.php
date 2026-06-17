<?php
/**
 * Tests for the Tax IDs Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Tax_Ids_Section;
use WP_UnitTestCase;

/**
 * Test_Tax_Ids_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Tax_Ids_Section extends WP_UnitTestCase {
	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_tax_ids' );
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * Write_map migrates from the legacy general['tax_ids'] location, in memory only.
	 */
	public function test_write_map_migrates_from_legacy_general(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array( 'tax_ids' => array( 'write_map' => array( 'abn' => '_abn_meta' ) ) )
		);

		$section  = new Tax_Ids_Section();
		$settings = $section->read();

		$this->assertEquals( array( 'abn' => '_abn_meta' ), $settings['write_map'] );
		$this->assertFalse( get_option( 'woocommerce_pos_settings_tax_ids' ), 'Pure read must not create the option' );
	}

	/**
	 * Merge() fully replaces write_map so users can remove entries.
	 */
	public function test_merge_replaces_write_map(): void {
		$section = new Tax_Ids_Section();
		$merged  = $section->merge(
			array(
				'write_map' => array(
					'abn' => '_a',
					'vat' => '_v',
				),
			),
			array( 'write_map' => array( 'abn' => '_new' ) )
		);
		$this->assertEquals( array( 'abn' => '_new' ), $merged['write_map'] );
	}
}
