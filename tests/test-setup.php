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

	
	public function test_wp_phpunit_is_loaded_via_composer(): void {
		$this->assertStringStartsWith(
			\dirname(__DIR__) . '/vendor/',
			getenv('WP_PHPUNIT__DIR')
		);

		$this->assertStringStartsWith(
			\dirname(__DIR__) . '/vendor/',
			( new ReflectionClass('WP_UnitTestCase') )->getFileName()
		);
	}
}
