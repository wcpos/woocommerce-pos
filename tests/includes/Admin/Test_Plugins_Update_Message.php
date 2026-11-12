<?php
/**
 * Tests the plugin update message upgrade notice rendering.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Plugins;

/**
 * Upgrade notices render safe HTML inside a div and omit empty content.
 */
class Test_Plugins_Update_Message extends \WP_UnitTestCase {
	/**
	 * Test an HTML notice renders markup instead of escaped tags.
	 */
	public function test_plugin_update_message_html_notice_renders_markup(): void {
		// Arrange.
		$r = (object) array(
			'new_version'    => '2.0.1',
			'upgrade_notice' => '<p>x</p>',
		);

		// Act.
		ob_start();
		( new Plugins() )->plugin_update_message( array(), $r );
		$output = ob_get_clean();

		// Assert.
		$this->assertStringContainsString( '<p>x</p>', $output );
		$this->assertStringNotContainsString( '&lt;p&gt;', $output );
		$this->assertStringContainsString( 'Important:', $output );
	}

	/**
	 * Test the notice box uses a div rather than a paragraph.
	 */
	public function test_plugin_update_message_box_is_a_div_not_a_paragraph(): void {
		// Arrange.
		$r = (object) array(
			'new_version'    => '2.0.1',
			'upgrade_notice' => '<p>x</p>',
		);

		// Act.
		ob_start();
		( new Plugins() )->plugin_update_message( array(), $r );
		$output = ob_get_clean();

		// Assert.
		$this->assertStringStartsWith( '<div style="', $output );
		$this->assertStringEndsWith( '</div>', $output );
		$this->assertStringNotContainsString( '<p style=', $output );
	}

	/**
	 * Test unsafe markup is removed while safe markup remains.
	 */
	public function test_plugin_update_message_unsafe_markup_is_removed(): void {
		// Arrange.
		$r = (object) array(
			'new_version'    => '2.0.1',
			'upgrade_notice' => '<p>x</p><script>alert(1)</script>',
		);

		// Act.
		ob_start();
		( new Plugins() )->plugin_update_message( array(), $r );
		$output = ob_get_clean();

		// Assert.
		$this->assertStringContainsString( '<p>x</p>', $output );
		$this->assertStringNotContainsString( '<script', $output );
	}

	/**
	 * Test a plain notice prints its text.
	 */
	public function test_plugin_update_message_plain_notice_prints_text(): void {
		// Arrange.
		$r = (object) array(
			'new_version'    => '2.0.1',
			'upgrade_notice' => 'Sync every till first.',
		);

		// Act.
		ob_start();
		( new Plugins() )->plugin_update_message( array(), $r );
		$output = ob_get_clean();

		// Assert.
		$this->assertStringContainsString( 'Sync every till first.', $output );
	}

	/**
	 * Test a tags-only notice prints nothing.
	 */
	public function test_plugin_update_message_tags_only_notice_prints_nothing(): void {
		// Arrange.
		$r = (object) array(
			'new_version'    => '2.0.1',
			'upgrade_notice' => '<p> </p>',
		);

		// Act.
		ob_start();
		( new Plugins() )->plugin_update_message( array(), $r );
		$output = ob_get_clean();

		// Assert.
		$this->assertSame( '', $output );
	}

	/**
	 * Test a missing notice prints nothing.
	 */
	public function test_plugin_update_message_missing_notice_prints_nothing(): void {
		// Arrange.
		$r = (object) array( 'new_version' => '2.0.1' );

		// Act.
		ob_start();
		( new Plugins() )->plugin_update_message( array(), $r );
		$output = ob_get_clean();

		// Assert.
		$this->assertSame( '', $output );
	}
}
