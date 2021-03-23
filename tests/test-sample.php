<?php

use WCPOS\Run;

class WCPOS_Test_Sample extends WP_UnitTestCase {

	public function setup() {
		parent::setup();
	}

	public function tearDown() {
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function init() {
		$wcpos = Run::init();
		$this->assertTrue( is_a( $wcpos, '\WCPOS\WooCommercePOS\Run' ) );
	}
}
