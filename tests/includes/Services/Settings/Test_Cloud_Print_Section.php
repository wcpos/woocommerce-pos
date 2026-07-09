<?php
/**
 * Tests for the Cloud Print Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Cloud_Print_Section;
use WP_UnitTestCase;

/**
 * Test_Cloud_Print_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Cloud_Print_Section extends WP_UnitTestCase {
	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_cloud_print' );
		parent::tearDown();
	}

	/**
	 * Read redacts secrets and enriches printers with status/encoding fields.
	 */
	public function test_read_redacts_secrets(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'              => 'p1',
						'name'            => 'Front',
						'provider'        => 'star-cloudprnt',
						'poll_token_hash' => 'hash123',
					),
				),
				'assignments' => array(),
			)
		);

		$section  = new Cloud_Print_Section();
		$settings = $section->read();

		$this->assertArrayNotHasKey( 'poll_token_hash', $settings['printers'][0] );
		$this->assertEquals( 'esc-pos', $settings['printers'][0]['language'] );
		$this->assertEquals( 42, $settings['printers'][0]['columns'] );
		$this->assertArrayHasKey( 'status', $settings['printers'][0] );
	}

	/**
	 * Write preserves a stored PrintNode API key when the payload omits it.
	 */
	public function test_write_preserves_omitted_printnode_key(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'                   => 'pn1',
						'name'                 => 'Desk',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'secret-key',
						'printnode_printer_id' => 7,
						'printnode_format'     => 'pdf',
					),
				),
				'assignments' => array(),
			)
		);

		$section = new Cloud_Print_Section();
		$result  = $section->write(
			array(
				'printers' => array(
					array(
						'id'                   => 'pn1',
						'name'                 => 'Desk renamed',
						'provider'             => 'printnode',
						'printnode_api_key'    => '',
						'printnode_printer_id' => 7,
						'printnode_format'     => 'raw',
					),
				),
				'assignments' => array(),
			)
		);

		$this->assertIsArray( $result );
		$stored = get_option( 'woocommerce_pos_settings_cloud_print' );
		$this->assertEquals( 'secret-key', $stored['printers'][0]['printnode_api_key'] );
		// Response view must NOT contain the key.
		$this->assertArrayNotHasKey( 'printnode_api_key', $result['printers'][0] );
	}

	/**
	 * Duplicate printer ids are rejected with the route-specific error code.
	 */
	public function test_write_rejects_duplicate_ids(): void {
		$section = new Cloud_Print_Section();
		$result  = $section->write(
			array(
				'printers' => array(
					array(
						'id' => 'dup',
						'name' => 'A',
						'provider' => 'star-cloudprnt',
					),
					array(
						'id' => 'dup',
						'name' => 'B',
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'wcpos_cloud_print_duplicate_printer_id', $result->get_error_code() );
	}
}
