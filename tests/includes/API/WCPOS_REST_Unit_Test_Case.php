<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WP_REST_Request;
use WP_User;

/**
 * Base test class for WCPOS.
 */
abstract class WCPOS_REST_Unit_Test_Case extends WC_REST_Unit_Test_Case {
	/**
	 * @var Controller
	 */
	protected $endpoint;
	
	/**
	 * @var WP_User
	 */
	protected $user;

	public function setUp(): void {
		parent::setUp();
		$this->user = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user($this->user);

		/*
		 * Using $this->server->dispatch doesn't call rest_api_init
		 * We need to init the API manually to register the routes
		 */
		new API();
	}

	public function wp_rest_get_request($path = ''): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header('X-WCPOS', '1');
		$request->set_method('GET');
		$request->set_route($path);

		return $request;
	}

	public function wp_rest_post_request($path = ''): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header('X-WCPOS', '1');
		$request->set_method('POST');
		$request->set_route($path);

		return $request;
	}

	/**
	 * NOTE: all PATCH requests are sent as POST requests with a _method=PATCH query param.
	 * This is because PATCH requests are not supported by some servers.
	 *
	 * @param mixed $path
	 */
	public function wp_rest_patch_request($path = ''): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header('X-WCPOS', '1');
		$request->set_method('POST');
		$request->set_route($path);
		$request->set_query_params(array('_method' => 'PATCH'));

		return $request;
	}

	public function get_reflected_property_value($propertyName) {
		$reflection = new ReflectionClass($this->endpoint);
		$property   = $reflection->getProperty($propertyName);
		$property->setAccessible(true);

		return $property->getValue($this->endpoint);
	}
}
