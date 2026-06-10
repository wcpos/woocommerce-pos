<?php
/**
 * Tests for the General Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\General_Section;
use WP_UnitTestCase;

/**
 * Test_General_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_General_Section extends WP_UnitTestCase {
	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( 'woocommerce_pos_settings_tools' );
		parent::tearDown();
	}

	/**
	 * tracking_consent migrates from the legacy tools option, in memory only.
	 */
	public function test_tracking_consent_migrates_from_tools_without_db_write(): void {
		update_option( 'woocommerce_pos_settings_tools', array( 'tracking_consent' => 'allowed' ) );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );

		$section  = new General_Section();
		$settings = $section->read();

		$this->assertEquals( 'allowed', $settings['tracking_consent'] );
		// Pure read: the general option in the DB must NOT have been touched.
		$stored = get_option( 'woocommerce_pos_settings_general' );
		$this->assertArrayNotHasKey( 'tracking_consent', $stored );
	}

	/**
	 * An explicit general-level consent always wins over the legacy tools value.
	 */
	public function test_general_tracking_consent_wins_over_tools(): void {
		update_option( 'woocommerce_pos_settings_tools', array( 'tracking_consent' => 'allowed' ) );
		update_option( 'woocommerce_pos_settings_general', array( 'tracking_consent' => 'denied' ) );

		$section = new General_Section();
		$this->assertEquals( 'denied', $section->read()['tracking_consent'] );
	}

	/**
	 * Read composes store_defaults and sanitizes store_tax_ids.
	 */
	public function test_read_composes_store_defaults_and_sanitizes_tax_ids(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'store_tax_ids' => array(
					array( 'type' => 'abn', 'value' => ' 123 ' ),
					array( 'type' => '', 'value' => 'dropme' ),
					'not-an-array',
				),
			)
		);

		$section  = new General_Section();
		$settings = $section->read();

		$this->assertArrayHasKey( 'store_defaults', $settings );
		$this->assertCount( 1, $settings['store_tax_ids'] );
		$this->assertEquals( '123', $settings['store_tax_ids'][0]['value'] );
	}

	/**
	 * Sanitize strips the computed store_defaults field and cleans strings.
	 */
	public function test_sanitize_strips_store_defaults_on_write(): void {
		$section = new General_Section();
		$result  = $section->write(
			array(
				'store_name'     => '  My Store  ',
				'store_email'    => 'not-an-email',
				'store_defaults' => array( 'injected' => true ),
			)
		);

		$stored = get_option( 'woocommerce_pos_settings_general' );
		$this->assertArrayNotHasKey( 'store_defaults', $stored );
		$this->assertEquals( 'My Store', $stored['store_name'] );
		$this->assertEquals( '', $stored['store_email'] );
		$this->assertIsArray( $result );
	}

	/**
	 * merge() fully replaces store_tax_ids instead of deep-merging rows.
	 */
	public function test_merge_replaces_store_tax_ids(): void {
		$section = new General_Section();
		$merged  = $section->merge(
			array( 'store_tax_ids' => array( array( 'type' => 'abn', 'value' => '1' ), array( 'type' => 'vat', 'value' => '2' ) ) ),
			array( 'store_tax_ids' => array( array( 'type' => 'abn', 'value' => '9' ) ) )
		);
		$this->assertCount( 1, $merged['store_tax_ids'] );
		$this->assertEquals( '9', $merged['store_tax_ids'][0]['value'] );
	}
}
