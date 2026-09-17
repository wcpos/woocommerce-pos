<?php
/**
 * Tests for the wcpos/v2 order-email service.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;

/**
 * The wcpos/v2 order-email registration and route-boundary tests.
 */
class Test_V2_Order_Email_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable the sync surface before REST routes are registered.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * The promoted email action registers and matches the authorized v1 gate.
	 */
	public function test_v2_order_email_route_matches_v1_authorized_status(): void {
		$route_pattern = 'orders/(?P<order_id>[\d]+)/email';
		$this->assertArrayHasKey( '/wcpos/v1/' . $route_pattern, $this->server->get_routes( 'wcpos/v1' ) );
		$this->assertArrayHasKey( '/wcpos/v2/' . $route_pattern, $this->server->get_routes( 'wcpos/v2' ) );

		$email_attempted = false;
		$email_callback  = function () use ( &$email_attempted ): void {
			$email_attempted = true;
		};
		add_action( 'woocommerce_before_resend_order_emails', $email_callback );

		$order       = OrderHelper::create_order();
		$v1_request  = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/email' );
		$v1_request->set_body_params( array( 'email' => 'v2-service-test@example.com' ) );
		$v1_response  = $this->server->dispatch( $v1_request );
		$v1_attempted = $email_attempted;

		$email_attempted = false;
		$v2_request      = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/email' );
		$v2_request->set_body_params( array( 'email' => 'v2-service-test@example.com' ) );
		$v2_response  = $this->server->dispatch( $v2_request );
		$v2_attempted = $email_attempted;

		remove_action( 'woocommerce_before_resend_order_emails', $email_callback );

		$this->assertSame( 200, $v1_response->get_status() );
		$this->assertSame( $v1_response->get_status(), $v2_response->get_status() );
		$this->assertTrue( $v1_attempted );
		$this->assertTrue( $v2_attempted );
	}

	/**
	 * The email routes declare a callable route schema, so the `help` index renders.
	 *
	 * WordPress calls `call_user_func( $options['schema'] )` for every registered
	 * route whenever a namespace index is requested with `context=help`. A route
	 * registered with `'schema' => array()` passes `isset()` and then fatals with a
	 * TypeError, taking the whole `/wcpos/v2` (and `/wcpos/v1`) index down.
	 */
	public function test_order_email_routes_render_help_index_with_route_schema(): void {
		// The help index is keyed by the raw registered pattern, not the `{order_id}` form.
		$route_pattern = '/orders/(?P<order_id>[\d]+)/email';

		foreach ( array( 'wcpos/v2', 'wcpos/v1' ) as $namespace ) {
			$request = $this->wp_rest_get_request( '/' . $namespace );
			$request->set_query_params( array( 'context' => 'help' ) );

			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), $namespace . ' help index' );
			$this->assertArrayHasKey( '/' . $namespace . $route_pattern, $data['routes'], $namespace . ' email route' );

			$schema = $data['routes'][ '/' . $namespace . $route_pattern ]['schema'];
			$this->assertSame( 'object', $schema['type'], $namespace . ' schema type' );
			$this->assertSame( 'boolean', $schema['properties']['success']['type'], $namespace . ' success property' );
		}
	}

	/**
	 * The v2 orders collection stays on sync; legacy data routes stay absent.
	 */
	public function test_v2_orders_keeps_sync_routes_without_legacy_data_pattern(): void {
		$routes = $this->server->get_routes( 'wcpos/v2' );

		$this->assertArrayHasKey( '/wcpos/v2/orders', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v2/orders/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/orders/(?P<order_id>[\d]+)/email', $routes );
	}
}
