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
	 * The v2 email route accepts only an email address.
	 */
	public function test_send_email_invalid_address_returns_400(): void {
		// Arrange.
		$order   = OrderHelper::create_order();
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/email' );
		$request->set_body_params( array( 'email' => 'not-an-email' ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Saving the address to another cashier's order needs edit_others_shop_orders.
	 */
	public function test_send_email_save_to_billing_non_owner_returns_403(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_user', (string) get_current_user_id() );
		$order->save();
		$billing_before = $order->get_billing_email();
		$staff          = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		foreach ( array( 'access_woocommerce_pos', 'publish_shop_orders', 'edit_shop_orders' ) as $cap ) {
			$staff->add_cap( $cap );
		}
		wp_set_current_user( $staff->ID );
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/email' );
		$request->set_body_params(
			array(
				'email'   => 'someone-else@example.com',
				'save_to' => 'billing',
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpos_rest_cannot_edit_order', $response->get_data()['code'] );
		$this->assertSame( $billing_before, wc_get_order( $order->get_id() )->get_billing_email() );
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
