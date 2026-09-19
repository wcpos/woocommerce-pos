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

/**
 * A controller written against WP_REST_Server directly, not extending core's
 * base class, that keeps its own namespace property and reads it when it
 * registers. The registry must stamp it like any other promoted entry.
 */
class Registry_Plain_Settings_Test_Double {
	/**
	 * Endpoint namespace, as a core-shaped controller declares it.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/** Register the probe under whatever namespace this controller carries. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings/plain-probe',
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

/** A base class that keeps its namespace private and reads it when it registers. */
class Registry_Private_Base_Test_Double {
	/**
	 * Endpoint namespace, private to this class.
	 *
	 * @var string
	 */
	private $namespace = Controller_Registry::V1_NAMESPACE;

	/** Register the probe under whatever namespace this class holds. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings/private-probe',
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

/** The subclass the filter registers; the namespace it inherits is not in its own scope. */
class Registry_Private_Settings_Test_Double extends Registry_Private_Base_Test_Double {}

/**
 * A controller whose constructor assigns a namespace it never declares.
 *
 * The attribute is deliberate: the shape under test IS the dynamic property, and
 * PHP 8.2+ deprecates creating one without it. PHP 7.4 reads the line as a comment.
 */
#[\AllowDynamicProperties]
class Registry_Dynamic_Settings_Test_Double {
	/** Assign the namespace without a property declaration. */
	public function __construct() {
		$this->namespace = Controller_Registry::V1_NAMESPACE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}

	/** Register the probe under whatever namespace this instance holds. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings/dynamic-probe',
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

/**
 * A subclass whose constructor assigns a namespace dynamically while the base
 * keeps a private one: two slots again, and the base's is invisible from here.
 */
#[\AllowDynamicProperties]
class Registry_Dynamic_Over_Private_Test_Double extends Registry_Private_Base_Test_Double {
	/** Assign the namespace the subclass reads, without declaring it. */
	public function __construct() {
		$this->namespace = Controller_Registry::V1_NAMESPACE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}

	/** Register the parent's probe, then one under this instance's own namespace. */
	public function register_routes(): void {
		parent::register_routes();
		register_rest_route(
			$this->namespace,
			'/settings/dynamic-over-private-probe',
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

/** A subclass that declares its own namespace while the base keeps a private one. */
class Registry_Shadowed_Settings_Test_Double extends Registry_Private_Base_Test_Double {
	/**
	 * The subclass's own slot; the base's private slot is a second one.
	 *
	 * @var string
	 */
	protected $namespace = Controller_Registry::V1_NAMESPACE;
}

/** A v2-filter replacement that deliberately serves nothing. */
class Registry_Silent_Settings_Test_Double {
	/** Register no route at all. */
	public function register_routes(): void {}
}

/** A v1 replacement that also registers under a plugin-added WCPOS namespace. */
class Registry_Namespaced_Stores_Test_Double extends \WCPOS\WooCommercePOS\API\V1\Stores {
	/** Register the parent routes and a probe under the extension namespace. */
	public function register_routes(): void {
		parent::register_routes();
		register_rest_route(
			'wcpos-ext/v1',
			'/probe',
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

	/**
	 * Extra WCPOS namespace filter.
	 *
	 * @var \Closure|null
	 */
	private $namespaces_filter;

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
		if ( 'test_a_promoted_controller_outside_core_s_base_class_is_stamped_v2' === $this->getName() ) {
			$this->v2_filter = static function ( array $map ): array {
				$map['settings'] = Registry_Plain_Settings_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( 'test_a_namespace_the_constructor_assigned_without_declaring_is_stamped' === $this->getName() ) {
			$this->v2_filter = static function ( array $map ): array {
				$map['settings'] = Registry_Dynamic_Settings_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( 'test_a_dynamic_namespace_over_a_private_base_slot_stamps_both' === $this->getName() ) {
			$this->v2_filter = static function ( array $map ): array {
				$map['settings'] = Registry_Dynamic_Over_Private_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( 'test_a_shadowed_namespace_is_stamped_in_every_scope_that_holds_one' === $this->getName() ) {
			$this->v2_filter = static function ( array $map ): array {
				$map['settings'] = Registry_Shadowed_Settings_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( 'test_a_namespace_private_to_a_base_class_is_stamped_in_its_own_scope' === $this->getName() ) {
			$this->v2_filter = static function ( array $map ): array {
				$map['settings'] = Registry_Private_Settings_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( 'test_a_readonly_namespace_is_left_alone_rather_than_fatally_rewritten' === $this->getName() ) {
			if ( version_compare( PHP_VERSION, '8.1', '>=' ) ) {
				self::define_readonly_double();
				$this->v2_filter = static function ( array $map ): array {
					$map['settings'] = 'Registry_Readonly_Settings_Test_Double';
					return $map;
				};
				add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
			}
		}
		if ( 'test_a_v2_filter_replacement_that_serves_nothing_is_not_overridden_by_the_core_service' === $this->getName() ) {
			$this->v2_filter = static function ( array $map ): array {
				$map['settings'] = Registry_Silent_Settings_Test_Double::class;
				return $map;
			};
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', $this->v2_filter );
		}
		if ( 'test_routes_under_a_plugin_added_namespace_are_attributed' === $this->getName() ) {
			$this->namespaces_filter = static function ( array $namespaces ): array {
				$namespaces[] = 'wcpos-ext/v1';
				return $namespaces;
			};
			add_filter( 'woocommerce_pos_rest_namespaces', $this->namespaces_filter );
			$this->v1_filter = static function ( array $map ): array {
				$map['stores'] = Registry_Namespaced_Stores_Test_Double::class;
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
		if ( $this->namespaces_filter ) {
			remove_filter( 'woocommerce_pos_rest_namespaces', $this->namespaces_filter );
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

		// Assert. The v2-native services come first, then the promoted keys in v1
		// order. Deriving the native list keeps this true on a trunk that has
		// v2-native services of its own (next carries six more).
		$natives = array_keys( Controller_Registry::v2_map( array() ) );
		$this->assertSame( array_merge( $natives, array( 'auth', 'custom_service' ) ), array_keys( $v2 ) );
		$this->assertContains( 'ping', $natives );
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

	/** The sync controllers ride the v1 filter but are wcpos/v2-native: never promoted, never built twice. */
	public function test_a_v1_entry_that_serves_only_wcpos_v2_is_not_promoted(): void {
		// Arrange.
		$registry = $this->registry();
		$routes   = $this->server->get_routes( 'wcpos/v2' );

		// Act.
		$promoted_sync_keys = preg_grep( '/^v2-sync-/', array_keys( $registry->controllers() ) );
		$status_handlers    = array_filter( array_keys( $routes['/wcpos/v2/status'] ), 'is_int' );

		// Assert.
		$this->assertSame( array(), array_values( $promoted_sync_keys ) );
		$this->assertCount( 1, $status_handlers );
		$this->assertSame( 'sync-status', $registry->routes()['/wcpos/v2/status'] );
	}

	/** Promotion is not limited to subclasses of core's controller base class. */
	public function test_a_promoted_controller_outside_core_s_base_class_is_stamped_v2(): void {
		// Arrange.
		$registry = $this->registry();

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings/plain-probe' ) );

		// Assert.
		$this->assertArrayHasKey( '/wcpos/v2/settings/plain-probe', $this->server->get_routes( 'wcpos/v2' ) );
		$this->assertArrayNotHasKey( '/wcpos/v2/settings/plain-probe', $this->server->get_routes( 'wcpos/v1' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( Registry_Plain_Settings_Test_Double::class, $registry->controllers()['v2-settings'] );
	}

	/** A namespace held only on the instance is still the one the controller reads. */
	public function test_a_namespace_the_constructor_assigned_without_declaring_is_stamped(): void {
		// Arrange.
		$frozen_probe = '/' . Controller_Registry::V1_NAMESPACE . '/settings/dynamic-probe';

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings/dynamic-probe' ) );

		// Assert.
		$this->assertArrayHasKey( '/wcpos/v2/settings/dynamic-probe', $this->server->get_routes( 'wcpos/v2' ) );
		$this->assertArrayNotHasKey( $frozen_probe, $this->server->get_routes( Controller_Registry::V1_NAMESPACE ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/** An instance-only namespace can sit on top of a private base slot; both are read, both are stamped. */
	public function test_a_dynamic_namespace_over_a_private_base_slot_stamps_both(): void {
		// Arrange.
		$frozen = $this->server->get_routes( Controller_Registry::V1_NAMESPACE );
		$v2     = $this->server->get_routes( 'wcpos/v2' );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings/dynamic-over-private-probe' ) );

		// Assert. The base's inherited route reads the private slot and the
		// subclass's reads the instance slot, so both have to be written.
		$this->assertArrayHasKey( '/wcpos/v2/settings/private-probe', $v2 );
		$this->assertArrayHasKey( '/wcpos/v2/settings/dynamic-over-private-probe', $v2 );
		$this->assertArrayNotHasKey( '/' . Controller_Registry::V1_NAMESPACE . '/settings/private-probe', $frozen );
		$this->assertSame( 200, $response->get_status() );
	}

	/** A private base slot and the subclass's own slot are two slots, and the inherited route reads the base's. */
	public function test_a_shadowed_namespace_is_stamped_in_every_scope_that_holds_one(): void {
		// Arrange.
		$frozen_probe = '/' . Controller_Registry::V1_NAMESPACE . '/settings/private-probe';

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings/private-probe' ) );

		// Assert. The inherited register_routes() reads the base's private slot,
		// so stamping only the subclass's would leave this route on the frozen lane.
		$this->assertArrayHasKey( '/wcpos/v2/settings/private-probe', $this->server->get_routes( 'wcpos/v2' ) );
		$this->assertArrayNotHasKey( $frozen_probe, $this->server->get_routes( Controller_Registry::V1_NAMESPACE ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/** A subclass must not get a dynamic property while the base keeps registering on its own namespace. */
	public function test_a_namespace_private_to_a_base_class_is_stamped_in_its_own_scope(): void {
		// Arrange.
		$frozen_probe = '/' . Controller_Registry::V1_NAMESPACE . '/settings/private-probe';

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings/private-probe' ) );

		// Assert. A dynamic property on the subclass would leave the inherited
		// register_routes() reading the base's original value, so the probe would
		// be on the frozen lane instead.
		$this->assertArrayHasKey( '/wcpos/v2/settings/private-probe', $this->server->get_routes( 'wcpos/v2' ) );
		$this->assertArrayNotHasKey( $frozen_probe, $this->server->get_routes( Controller_Registry::V1_NAMESPACE ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Declare the readonly double. `readonly` does not parse below PHP 8.1, and
	 * the suite runs on 7.4, so the class cannot be written out in this file.
	 */
	private static function define_readonly_double(): void {
		if ( class_exists( 'Registry_Readonly_Settings_Test_Double' ) ) {
			return;
		}
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- The only way to express PHP 8.1 syntax in a 7.4-parsed suite.
		eval(
			'class Registry_Readonly_Settings_Test_Double {
				public readonly string $namespace;
				public function __construct() { $this->namespace = \WCPOS\WooCommercePOS\API\Controller_Registry::V1_NAMESPACE; }
				public function register_routes(): void {
					register_rest_route( $this->namespace, "/settings/readonly-probe", array(
						"methods" => "GET",
						"callback" => "__return_true",
						"permission_callback" => "__return_true",
					) );
				}
			}'
		);
	}

	/** A controller that declared its namespace readonly keeps it, and must not fatal registration. */
	public function test_a_readonly_namespace_is_left_alone_rather_than_fatally_rewritten(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$this->markTestSkipped( 'readonly properties need PHP 8.1.' );
		}

		// Arrange.
		$registry = $this->registry();

		// Act. Registration already ran in setUp; reaching here at all is the point.
		$v1_routes = $this->server->get_routes( 'wcpos/v1' );
		$v2_routes = $this->server->get_routes( 'wcpos/v2' );

		// Assert. The frozen lane's path is built from the constant on purpose: a
		// wcpos/v1 route literal anywhere in this class marks every case in it as
		// legacy-only for the lane-coverage gate (tests/lane-coverage/README.md).
		$frozen_probe = '/' . Controller_Registry::V1_NAMESPACE . '/settings/readonly-probe';
		$this->assertArrayHasKey( $frozen_probe, $v1_routes );
		$this->assertArrayNotHasKey( '/wcpos/v2/settings/readonly-probe', $v2_routes );
		$this->assertInstanceOf( 'Registry_Readonly_Settings_Test_Double', $registry->controllers()['v2-settings'] );
		$this->assertArrayHasKey( '/wcpos/v2/status', $v2_routes );
	}

	/** An explicit v2-filter choice stands even when it serves nothing; only derived entries fall back. */
	public function test_a_v2_filter_replacement_that_serves_nothing_is_not_overridden_by_the_core_service(): void {
		// Arrange.
		$registry = $this->registry();

		// Act.
		$v2_routes = $this->server->get_routes( 'wcpos/v2' );

		// Assert.
		$this->assertArrayNotHasKey( '/wcpos/v2/settings', $v2_routes );
		$this->assertArrayHasKey( '/wcpos/v1/settings', $this->server->get_routes( 'wcpos/v1' ) );
		$this->assertInstanceOf( Registry_Silent_Settings_Test_Double::class, $registry->controllers()['v2-settings'] );
	}

	/** A filtered controller's routes under a namespace added through woocommerce_pos_rest_namespaces are attributed. */
	public function test_routes_under_a_plugin_added_namespace_are_attributed(): void {
		// Arrange.
		$registry = $this->registry();

		// Act.
		$ext_controller = $registry->controller_for_route( '/wcpos-ext/v1/probe' );
		$v2_controller  = $registry->controller_for_route( '/wcpos/v2/stores' );

		// Assert.
		$this->assertInstanceOf( Registry_Namespaced_Stores_Test_Double::class, $ext_controller );
		$this->assertInstanceOf( Registry_Namespaced_Stores_Test_Double::class, $v2_controller );
		$this->assertNotSame( $ext_controller, $v2_controller );
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
