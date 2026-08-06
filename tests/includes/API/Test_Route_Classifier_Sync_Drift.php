<?php
/**
 * Drift guard for the sync admin operation classification.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use WCPOS\WooCommercePOS\Sync\Api as Sync_Api;
use WCPOS\WooCommercePOS\Sync\Response_Telemetry;

/**
 * Sync admin operation classification drift guard.
 *
 * Route knowledge lives in several hand-maintained places: the per-controller
 * `register_rest_route()` calls, `Sync\Api::ADMIN_OP_PATHS`, and the literal
 * route lists inside `Sync\Response_Telemetry`. Nothing derives one from
 * another, so adding a route only to the controller leaves the other lists
 * silently stale. These tests walk the LIVE route table and assert both
 * directions, turning that silent drift into a red test.
 */
class Test_Route_Classifier_Sync_Drift extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable sync before REST initialization.
	 */
	public function setUp(): void {
		update_option( Sync_Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Restore the pre-test database state.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Sync_Api::OPTION_ENABLED );
	}

	/**
	 * Every classified admin operation is registered by a sync controller.
	 */
	public function test_sync_admin_op_paths_match_registered_routes(): void {
		$routes = rest_get_server()->get_routes( Sync_Api::ROUTE_NAMESPACE );

		foreach ( Sync_Api::ADMIN_OP_PATHS as $path ) {
			$route = '/' . Sync_Api::ROUTE_NAMESPACE . '/' . Sync_Api::ROUTE_PREFIX . $path;
			$this->assertArrayHasKey( $route, $routes, $route );
		}
	}

	/**
	 * Reverse direction: the admin gate and `ADMIN_OP_PATHS` describe the same set.
	 *
	 * DRIFT CAUGHT: a wcpos/v2 route registered with
	 * `array( $this, 'admin_permissions_check' )` but never added to
	 * `Sync\Api::ADMIN_OP_PATHS` (the route would be admin-gated at dispatch yet
	 * invisible to `Route_Classifier`'s `admin_op` classification), or an
	 * `ADMIN_OP_PATHS` entry whose route no longer uses the admin gate.
	 *
	 * WHEN IT FIRES: add the reported path to `Sync\Api::ADMIN_OP_PATHS`
	 * (`includes/Sync/Api.php`) — or, if the route intentionally left the admin
	 * tier, remove its stale entry from that constant.
	 */
	public function test_v2_admin_gated_routes_appear_in_admin_op_paths(): void {
		// Arrange.
		$base   = '/' . Sync_Api::ROUTE_NAMESPACE . '/' . Sync_Api::ROUTE_PREFIX;
		$routes = rest_get_server()->get_routes( Sync_Api::ROUTE_NAMESPACE );

		// Act.
		$gated = array();
		foreach ( $routes as $route => $handlers ) {
			foreach ( (array) $handlers as $handler ) {
				if ( 'admin_permissions_check' === self::callable_method( $handler['permission_callback'] ?? null ) ) {
					$gated[ substr( untrailingslashit( $route ), \strlen( $base ) ) ] = true;
				}
			}
		}
		$gated = array_keys( $gated );
		sort( $gated );

		$declared = Sync_Api::ADMIN_OP_PATHS;
		sort( $declared );

		// Assert.
		$this->assertNotEmpty( $gated, 'No wcpos/v2 route uses admin_permissions_check — the guard would pass vacuously.' );
		$this->assertEquals(
			$declared,
			$gated,
			'Admin-gated wcpos/v2 routes and Sync\Api::ADMIN_OP_PATHS have drifted apart. Update ADMIN_OP_PATHS in includes/Sync/Api.php.'
		);
	}

	/**
	 * Reverse direction: every live sync-lane route is classified by the telemetry predicate.
	 *
	 * DRIFT CAUGHT: a new route registered by one of the sync controllers
	 * (the `Sync\Api::register_controllers()` map) that was never added to
	 * `Sync\Response_Telemetry::is_sync_route()`. Such a route silently loses
	 * its contextual telemetry — no `X-Server-Load`, no `Server-Timing`, no
	 * request timing — while looking completely healthy in every other test.
	 *
	 * WHEN IT FIRES: add the reported route to the literal list in
	 * `Response_Telemetry::is_sync_route()` (`includes/Sync/Response_Telemetry.php`),
	 * and consider whether it also belongs in `is_metrics_route()` or
	 * `is_change_candidate_route()` in the same file.
	 *
	 * NOTE: the promoted wcpos/v2 service pass-through controllers (the
	 * `API\V2\*` subclasses of the v1 controllers, registered through
	 * `woocommerce_pos_rest_api_v2_controllers`) share the namespace but are
	 * deliberately NOT sync routes, so ownership is derived from the sync
	 * controller map rather than from the namespace.
	 */
	public function test_sync_controller_routes_are_recognized_by_response_telemetry(): void {
		// Arrange.
		$sync_controllers = array_values( Sync_Api::register_controllers( array() ) );
		$this->assertNotEmpty( $sync_controllers, 'Sync controllers are not registered — enable the sync feature flag first.' );

		$routes = rest_get_server()->get_routes( Sync_Api::ROUTE_NAMESPACE );

		// Act.
		$sync_routes = array();
		foreach ( $routes as $route => $handlers ) {
			if ( self::is_owned_by( (array) $handlers, $sync_controllers ) ) {
				$sync_routes[] = $route;
			}
		}

		// Assert.
		$this->assertNotEmpty( $sync_routes, 'No wcpos/v2 route resolved to a sync controller — the guard would pass vacuously.' );
		foreach ( $sync_routes as $route ) {
			$this->assertTrue(
				self::is_sync_route( $route ),
				sprintf(
					'Route %s is registered by a sync controller but Response_Telemetry::is_sync_route() does not recognize it. Add it to the list in includes/Sync/Response_Telemetry.php.',
					$route
				)
			);
		}
	}

	/**
	 * Call the private telemetry predicate under test.
	 *
	 * @param string $route Registered route.
	 */
	private static function is_sync_route( string $route ): bool {
		$method = new ReflectionMethod( Response_Telemetry::class, 'is_sync_route' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $route );
	}

	/**
	 * Whether any handler of a route is bound to one of the given controller classes.
	 *
	 * @param array<int, array<string, mixed>> $handlers Route handlers.
	 * @param array<int, string>               $classes  Controller class names.
	 */
	private static function is_owned_by( array $handlers, array $classes ): bool {
		foreach ( $handlers as $handler ) {
			foreach ( array( 'callback', 'permission_callback' ) as $key ) {
				$object = self::callable_object( $handler[ $key ] ?? null );
				if ( null === $object ) {
					continue;
				}
				foreach ( $classes as $class ) {
					if ( $object instanceof $class ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Resolve the object a callable is bound to, for both `array( $this, … )` and closures.
	 *
	 * @param mixed $callable Registered callable.
	 *
	 * @return object|null
	 */
	private static function callable_object( $callable ) {
		if ( \is_array( $callable ) && isset( $callable[0] ) && \is_object( $callable[0] ) ) {
			return $callable[0];
		}

		if ( $callable instanceof Closure ) {
			return ( new ReflectionFunction( $callable ) )->getClosureThis();
		}

		return null;
	}

	/**
	 * The method name of an `array( $object, 'method' )` callable, otherwise an empty string.
	 *
	 * @param mixed $callable Registered callable.
	 */
	private static function callable_method( $callable ): string {
		if ( \is_array( $callable ) && isset( $callable[1] ) && \is_string( $callable[1] ) ) {
			return $callable[1];
		}

		return '';
	}
}
