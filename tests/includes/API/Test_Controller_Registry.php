<?php
/**
 * Tests for controller promotion, namespace stamping and route attribution.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Controller_Registry;
use WP_REST_Response;
use WP_REST_Server;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep the extension doubles with their registry tests.

/** A v1 replacement whose extra route must also be promoted. */
class Registry_Stores_Test_Double extends \WCPOS\WooCommercePOS\API\V1\Stores {
	/** Register the parent routes and the extension probe. */
	public function register_routes(): void {
		parent::register_routes();
		register_rest_route(
			$this->namespace,
			'/stores/registry-probe',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( array(), 200 );
				},
				'permission_callback' => '__return_true',
			)
		);
	}
}

/** A v2 filter replacement with no namespace override of its own. */
class Registry_Settings_Test_Double extends \WCPOS\WooCommercePOS\API\V1\Settings {
	/** Register the parent routes and the extension probe. */
	public function register_routes(): void {
		parent::register_routes();
		register_rest_route(
			$this->namespace,
			'/settings/registry-probe',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response( array(), 200 );
				},
				'permission_callback' => '__return_true',
			)
		);
	}
}

/** A pre-1.10 shaped auth replacement: a plain object that hard-codes the v1 namespace. */
class Registry_Legacy_Auth_Test_Double {
	/** Register only the historical v1 route. */
	public function register_routes(): void {
		register_rest_route(
			'wcpos/v1',
			'/auth/test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => '__return_true',
				'permission_callback' => '__return_true',
			)
		);
	}
}

/** The registry's map and registration contracts. */
class Test_Controller_Registry extends WCPOS_REST_Unit_Test_Case {
	/**
	 * API registered by the test harness.
	 *
	 * @var \WCPOS\WooCommercePOS\API
	 */
	private $api;

	/**
	 * V1 controller filter.
	 *
	 * @var \Closure|null
	 */
	private $v1_filter;

	/**
	 * V2 controller filter.
	 *
	 * @var \Closure|null
	 */
	private $v2_filter;

	/**
	 * Callback wrapping filter.
	 *
	 * @var \Closure|null
	 */
	private $endpoints_filter;

	/** Install each integration case's filters before route registration. */
	public function setUp(): void {
		if ( 'test_a_v1_replacement_registers_its_routes_under_v2_without_a_v2_entry' === $this->getName() ) {
			$this->v1_filter = static function ( array $map ): array {
				$map['stores'] = Registry_Stores_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_controllers', $this->v1_filter );
		}
		if ( 'test_a_replacement_that_registers_nothing_under_v2_leaves_the_lane_to_the_core_service' === $this->getName() ) {
			$this->v1_filter = static function ( array $map ): array {
				$map['auth'] = Registry_Legacy_Auth_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_controllers', $this->v1_filter );
		}
		if ( 'test_a_v2_filter_entry_without_a_namespace_override_is_stamped_v2' === $this->getName() ) {
			$this->v2_filter = static function ( array $map ): array {
				$map['settings'] = Registry_Settings_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( 'test_route_attribution_survives_closure_wrapped_callbacks' === $this->getName() ) {
			$this->endpoints_filter = static function ( array $routes ): array {
				foreach ( $routes as $route => &$handlers ) {
					if ( 0 !== strpos( $route, '/' . Controller_Registry::V2_NAMESPACE . '/' ) ) {
						continue;
					}
					foreach ( $handlers as &$handler ) {
						if ( isset( $handler['callback'] ) ) {
							$callback            = $handler['callback'];
							$handler['callback'] = static function ( ...$args ) use ( $callback ) {
								return $callback( ...$args );
							};
						}
					}
					unset( $handler );
				}
				return $routes;
			};
			add_filter( 'rest_endpoints', $this->endpoints_filter, 1 );
		}
		parent::setUp();
	}

	/** Retain the first API instance: a second registration has no new routes to diff. */
	public function rest_api_init(): void {
		$this->api = new \WCPOS\WooCommercePOS\API();
	}

	/** Remove only the filters this case installed. */
	public function tearDown(): void {
		if ( $this->v1_filter ) {
			remove_filter( 'woocommerce_pos_rest_api_controllers', $this->v1_filter );
		}
		if ( $this->v2_filter ) {
			remove_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( $this->endpoints_filter ) {
			remove_filter( 'rest_endpoints', $this->endpoints_filter, 1 );
		}
		parent::tearDown();
	}

	/** Read the registry used by the real registration path. */
	private function registry(): Controller_Registry {
		$property = new \ReflectionProperty( $this->api, 'registry' );
		$property->setAccessible( true );
		return $property->getValue( $this->api );
	}

	/** Frozen data controllers must never be promoted alongside services. */
	public function test_v2_map_promotes_every_v1_key_except_the_frozen_data_controllers(): void {
		// Arrange.
		$v1 = array(
			'auth'               => 'Fake_Auth',
			'products'           => 'Fake_Products',
			'product_variations' => 'Fake_Variations',
			'orders'             => 'Fake_Orders',
			'customers'          => 'Fake_Customers',
			'product_tags'       => 'Fake_Tags',
			'product_categories' => 'Fake_Categories',
			'product_brands'     => 'Fake_Brands',
			'coupons'            => 'Fake_Coupons',
			'taxes'              => 'Fake_Taxes',
			'custom_service'     => 'Fake_Custom',
		);

		// Act.
		$v2 = Controller_Registry::v2_map( $v1 );

		// Assert.
		$this->assertSame( array( 'ping', 'echo_probe', 'site', 'order_email', 'auth', 'custom_service' ), array_keys( $v2 ) );
		$this->assertSame( 'Fake_Auth', $v2['auth'] );
		$this->assertSame( 'Fake_Custom', $v2['custom_service'] );
	}

	/** V2-native controllers take precedence over promoted keys. */
	public function test_v2_map_v2_native_entry_wins_over_a_v1_key_of_the_same_name(): void {
		// Arrange.
		$v1 = array( 'ping' => 'Fake_Ping' );

		// Act.
		$v2 = Controller_Registry::v2_map( $v1 );

		// Assert.
		$this->assertSame( \WCPOS\WooCommercePOS\API\V2\Ping::class, $v2['ping'] );
	}

	/** The v2 filter runs after promotion and may extend or replace it. */
	public function test_v2_map_filter_can_add_and_replace_entries(): void {
		// Arrange.
		$this->v2_filter = static function ( array $map ): array {
			$map['extra'] = 'Fake_Extra';
			$map['auth']  = 'Replacement_Auth';
			return $map;
		};
		add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );

		// Act.
		$v2 = Controller_Registry::v2_map( array( 'auth' => 'Fake_Auth' ) );

		// Assert.
		$this->assertSame( 'Fake_Extra', $v2['extra'] );
		$this->assertSame( 'Replacement_Auth', $v2['auth'] );
	}

	/** A dispatch-hook controller added without freezing its key must fail here. */
	public function test_frozen_data_keys_are_exactly_the_v1_controllers_that_own_a_dispatch_hook(): void {
		// Arrange.
		$v1 = Controller_Registry::v1_map();

		// Act / Assert.
		foreach ( $v1 as $key => $class ) {
			$this->assertSame( in_array( $key, Controller_Registry::FROZEN_DATA_KEYS, true ), method_exists( $class, 'wcpos_dispatch_request' ), $key );
		}
	}

	/** A v1-only filter replacement must reach the current namespace. */
	public function test_a_v1_replacement_registers_its_routes_under_v2_without_a_v2_entry(): void {
		// Arrange.
		$routes = $this->server->get_routes( 'wcpos/v2' );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/stores/registry-probe' ) );

		// Assert.
		$this->assertArrayHasKey( '/wcpos/v2/stores/registry-probe', $routes );
		$this->assertSame( 200, $response->get_status() );
	}

	/** A v2-filtered subclass must not need a protected namespace override. */
	public function test_a_v2_filter_entry_without_a_namespace_override_is_stamped_v2(): void {
		// Arrange.
		$routes = $this->server->get_routes( 'wcpos/v2' );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings/registry-probe' ) );

		// Assert.
		$this->assertArrayHasKey( '/wcpos/v2/settings/registry-probe', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v1/settings/registry-probe', $this->server->get_routes( 'wcpos/v1' ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/** Wrapping callbacks must not hide the controller that registered a route. */
	public function test_route_attribution_survives_closure_wrapped_callbacks(): void {
		// Arrange.
		$registry = $this->registry();
		$routes   = $this->server->get_routes( 'wcpos/v2' );

		// Act.
		$controller = $registry->controller_for_route( '/wcpos/v2/settings' );

		// Assert.
		$this->assertArrayHasKey( '/wcpos/v2/settings', $routes );
		$this->assertInstanceOf( \Closure::class, $routes['/wcpos/v2/settings'][0]['callback'], 'The wrap the case exists for did not happen.' );
		$this->assertInstanceOf( \WCPOS\WooCommercePOS\API\V1\Settings::class, $controller );
	}

	/** A legacy v1-only replacement must not take wcpos/v2 away from the core service. */
	public function test_a_replacement_that_registers_nothing_under_v2_leaves_the_lane_to_the_core_service(): void {
		// Arrange.
		$registry = $this->registry();

		// Act.
		$v1_controller = $registry->controller_for_route( '/wcpos/v1/auth/test' );
		$v2_controller = $registry->controller_for_route( '/wcpos/v2/auth/test' );
		$response      = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/auth/test' ) );

		// Assert.
		$this->assertInstanceOf( Registry_Legacy_Auth_Test_Double::class, $v1_controller );
		$this->assertInstanceOf( \WCPOS\WooCommercePOS\API\V1\Auth::class, $v2_controller );
		$this->assertSame( 200, $response->get_status() );
	}

	/** Unrelated WooCommerce routes must never acquire a WCPOS dispatch controller. */
	public function test_an_unmapped_route_has_no_controller(): void {
		// Arrange.
		$registry = $this->registry();

		// Act / Assert.
		$this->assertNull( $registry->controller_for_route( '/wc/v3/products' ) );
		$this->assertNotNull( $registry->controller_for_route( '/wcpos/v2/settings' ) );
	}
}
