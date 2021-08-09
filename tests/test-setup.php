<?php

class TestSetup extends WP_UnitTestCase {

	public function setup() {
		parent::setup();
	}

	public function tearDown() {
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function test_wordpress_and_plugin_are_loaded() {
		self::assertTrue( function_exists( 'do_action' ) );
		$this->assertTrue( class_exists( 'WCPOS\\WooCommercePOS\\Activator' ) );
	}

	/**
	 * @test
	 */
	public function test_wp_phpunit_is_loaded_via_composer() {
		$this->assertStringStartsWith(
			dirname( __DIR__ ) . '/vendor/',
			getenv( 'WP_PHPUNIT__DIR' )
		);

		$this->assertStringStartsWith(
			dirname( __DIR__ ) . '/vendor/',
			( new ReflectionClass( 'WP_UnitTestCase' ) )->getFileName()
		);
	}
}
