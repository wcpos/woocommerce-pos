<?php

/**
 * @internal
 *
 * @coversNothing
 */
class TestSetup extends WP_UnitTestCase {
	public function setup(): void {
		parent::setup();
	}

	public function tearDown(): void {
		parent::tearDown();
	}


	public function test_wordpress_and_plugin_are_loaded(): void {
		self::assertTrue(\function_exists('do_action'));
		$this->assertTrue(class_exists('WCPOS\\WooCommercePOS\\Activator'));
	}
}
