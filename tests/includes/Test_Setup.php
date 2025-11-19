<?php

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Setup extends WP_UnitTestCase {
	public function setup(): void {
		parent::setup();
	}

	public function tearDown(): void {
		parent::tearDown();
	}


	public function test_wordpress_and_plugin_are_loaded(): void {
		// wordpress
		self::assertTrue( \function_exists( 'do_action' ) );

		// WCPOS plugin
		$this->assertTrue( class_exists( 'WCPOS\WooCommercePOS\Activator' ) );
		$this->assertEquals( 'wcpos', \constant( 'WCPOS\WooCommercePOS\SHORT_NAME' ) );

		// woocommerce plugin and test helpers
		self::assertTrue( \function_exists( 'wc_create_new_customer' ) );
		// $this->assertTrue(class_exists('WC_Helper_Order'));
	}
}
