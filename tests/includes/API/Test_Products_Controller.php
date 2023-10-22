<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API\Products_Controller;

require_once \WC_UNIT_TEST_PATH . '/includes/wp-http-testcase.php';
require_once \WC_UNIT_TEST_PATH . '/framework/class-wc-rest-unit-test-case.php';

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Products_Controller extends WC_REST_Unit_Test_Case {
	/**
	 * @var Products_Controller
	 */
	protected $controller;

	/**
	 * @var WP_REST_Request
	 */
	protected $request;

	
	public function setup(): void {
		parent::setUp();

		$this->controller = new Products_Controller();
		$this->request    = $this->getMockBuilder('WP_REST_Request')->getMock();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_namespace_property(): void {
		$reflection         = new ReflectionClass($this->controller);
		$namespace_property = $reflection->getProperty('namespace');
		$namespace_property->setAccessible(true);
		
		$this->assertEquals('wcpos/v1', $namespace_property->getValue($this->controller));
	}

	public function test_rest_base(): void {
		$reflection         = new ReflectionClass($this->controller);
		$rest_base_property = $reflection->getProperty('rest_base');
		$rest_base_property->setAccessible(true);
		
		$this->assertEquals('products', $rest_base_property->getValue($this->controller));
	}
}
