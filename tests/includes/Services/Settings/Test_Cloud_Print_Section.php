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
		$this->assertEquals( 'star-prnt', $settings['printers'][0]['language'] );
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

	/**
	 * It clears a template the printer cannot render, and still saves.
	 *
	 * Epson SDP speaks only the thermal pipeline, so a legacy-php template
	 * renders to nothing. Rejecting the write blocked every later settings
	 * save — including the one that would have fixed the row — so the pairing
	 * is cleared instead, leaving the rule visibly incomplete.
	 */
	public function test_write_clears_template_engine_the_provider_cannot_render(): void {
		// Arrange.
		$section = new Cloud_Print_Section();

		// Act.
		$result = $section->write(
			array(
				'printers'    => array(
					array(
						'id'       => 'front',
						'name'     => 'Front counter',
						'provider' => 'epson-sdp',
					),
				),
				'assignments' => array(
					array(
						'printer_id'  => 'front',
						'template_id' => 'plugin-core',
						'scope'       => 'every',
					),
				),
			)
		);

		// Assert: saved, with the unrenderable template cleared.
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', $result['assignments'][0]['template_id'] );
		$this->assertSame( 'front', $result['assignments'][0]['printer_id'] );
	}

	/**
	 * An unrenderable row must never block an unrelated settings save.
	 *
	 * This is the regression that mattered: the picker filters by engine, so a
	 * stored pairing it cannot display was invisible — and while the write was
	 * rejected, every save failed and the screen silently reverted.
	 */
	public function test_write_is_not_blocked_by_an_unrenderable_row(): void {
		// Arrange: one bad row, plus a good one the admin is adding.
		$section = new Cloud_Print_Section();

		// Act.
		$result = $section->write(
			array(
				'printers'    => array(
					array(
						'id'       => 'front',
						'name'     => 'Front counter',
						'provider' => 'epson-sdp',
					),
					array(
						'id'                   => 'office',
						'name'                 => 'Office',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'key',
						'printnode_printer_id' => 42,
					),
				),
				'assignments' => array(
					array(
						'printer_id'  => 'front',
						'template_id' => 'plugin-core',
						'scope'       => 'every',
					),
					array(
						'printer_id'  => 'office',
						'template_id' => 'plugin-core',
						'scope'       => 'every',
					),
				),
			)
		);

		// Assert: the save succeeds and the valid row is untouched.
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', $result['assignments'][0]['template_id'] );
		$this->assertSame( 'plugin-core', $result['assignments'][1]['template_id'] );
	}

	/**
	 * It accepts a legacy template for a provider that renders every engine.
	 */
	public function test_write_accepts_any_engine_for_an_all_engine_provider(): void {
		// Arrange.
		$section = new Cloud_Print_Section();

		// Act.
		$result = $section->write(
			array(
				'printers'    => array(
					array(
						'id'                   => 'office',
						'name'                 => 'Office',
						'provider'             => 'printnode',
						'printnode_api_key'    => 'key',
						'printnode_printer_id' => 42,
					),
				),
				'assignments' => array(
					array(
						'printer_id'  => 'office',
						'template_id' => 'plugin-core',
						'scope'       => 'every',
					),
				),
			)
		);

		// Assert.
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'plugin-core', $result['assignments'][0]['template_id'] );
	}

	/**
	 * It still saves when the assigned template no longer exists.
	 *
	 * An unresolvable template cannot be classified, and blocking the whole
	 * save over one would stop unrelated edits.
	 */
	public function test_write_allows_an_unresolvable_template(): void {
		// Arrange.
		$section = new Cloud_Print_Section();

		// Act.
		$result = $section->write(
			array(
				'printers'    => array(
					array(
						'id'       => 'front',
						'name'     => 'Front counter',
						'provider' => 'epson-sdp',
					),
				),
				'assignments' => array(
					array(
						'printer_id'  => 'front',
						'template_id' => '99999999',
						'scope'       => 'every',
					),
				),
			)
		);

		// Assert.
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}
}
