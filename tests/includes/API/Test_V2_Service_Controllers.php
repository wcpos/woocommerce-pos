<?php
/**
 * Tests for the wcpos/v2 service controller pass-throughs.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * wcpos/v2 service controller registration and permission-gate tests.
 */
class Test_V2_Service_Controllers extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Every promoted service keeps its v1 route and responds identically through v2.
	 *
	 * @dataProvider service_routes
	 *
	 * @param string $service       Service name.
	 * @param string $method        HTTP method.
	 * @param string $route_path    Concrete route path without namespace.
	 * @param string $route_pattern Registered route pattern without namespace.
	 */
	public function test_v1_and_v2_service_routes_are_registered_and_match_authorized_status(
		string $service,
		string $method,
		string $route_path,
		string $route_pattern
	): void {
		$route_path = str_replace( '{user_id}', (string) $this->user, $route_path );
		$v1_path    = '/wcpos/v1/' . $route_path;
		$v2_path    = '/wcpos/v2/' . $route_path;
		$v1_pattern = '/wcpos/v1/' . $route_pattern;
		$v2_pattern = '/wcpos/v2/' . $route_pattern;
		$v1_routes  = $this->server->get_routes( 'wcpos/v1' );
		$v2_routes  = $this->server->get_routes( 'wcpos/v2' );

		$this->assertArrayHasKey( $v1_pattern, $v1_routes, $service . ' v1 route is missing.' );
		$this->assertArrayHasKey( $v2_pattern, $v2_routes, $service . ' v2 route is missing.' );

		$v1_response = $this->server->dispatch( $this->service_request( $method, $v1_path ) );
		$v2_response = $this->server->dispatch( $this->service_request( $method, $v2_path ) );

		$this->assertNotSame(
			'rest_no_route',
			$this->response_error_code( $v1_response ),
			$service . ' v1 route did not respond.'
		);
		$this->assertNotSame(
			'rest_no_route',
			$this->response_error_code( $v2_response ),
			$service . ' v2 route did not respond.'
		);
		$this->assertSame(
			$v1_response->get_status(),
			$v2_response->get_status(),
			$service . ' status differs between v1 and v2.'
		);
	}

	/**
	 * Service route matrix.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public function service_routes(): array {
		return array(
			'auth'              => array( 'Auth', 'GET', 'auth/test', 'auth/test' ),
			'settings'          => array( 'Settings', 'GET', 'settings', 'settings' ),
			'cashier'           => array( 'Cashier', 'GET', 'cashier/{user_id}', 'cashier/(?P<id>[\d]+)' ),
			'templates'         => array( 'Templates_Controller', 'GET', 'templates', 'templates' ),
			'receipts'          => array( 'Receipts_Controller', 'GET', 'receipts/0', 'receipts/(?P<order_id>[\d]+)' ),
			'print_jobs'        => array( 'Print_Jobs_Controller', 'GET', 'print-jobs', 'print-jobs' ),
			'stores'            => array( 'Stores', 'GET', 'stores', 'stores' ),
			'extensions'        => array( 'Extensions', 'GET', 'extensions', 'extensions' ),
			'logs'              => array( 'Logs', 'GET', 'logs', 'logs' ),
			'payment_gateways'  => array( 'Payment_Gateways', 'GET', 'payment-gateways', 'payment-gateways' ),
			'gateway_bootstrap' => array(
				'Gateway_Bootstrap_Controller',
				'POST',
				'payment-gateways/not-a-gateway/bootstrap',
				'payment-gateways/(?P<gateway_id>[a-zA-Z0-9_-]+)/bootstrap',
			),
			'checkout'          => array( 'Checkout_Controller', 'GET', 'orders/0/checkout', 'orders/(?P<id>[\d]+)/checkout' ),
			'shipping_methods'  => array( 'Shipping_Methods_Controller', 'GET', 'shipping_methods', 'shipping_methods' ),
			'order_statuses'    => array(
				'Data_Order_Statuses_Controller',
				'GET',
				'data/order_statuses',
				'data/order_statuses',
			),
		);
	}

	/**
	 * Anonymous v2 auth test requests inherit the public route classification.
	 */
	public function test_anonymous_v2_auth_test_bypasses_permission_gate(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/auth/test' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( 'woocommerce_pos_rest_unauthorized', $this->response_error_code( $response ) );
	}

	/**
	 * Anonymous v2 settings requests still receive the baseline permission gate.
	 */
	public function test_anonymous_v2_settings_receives_permission_gate_401(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $this->response_error_code( $response ) );
	}

	/**
	 * V2 printer-token routes reach their route-level token permission callback.
	 */
	public function test_anonymous_v2_printer_token_routes_bypass_baseline_gate(): void {
		wp_set_current_user( 0 );

		$cloudprnt = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/print-jobs/cloudprnt' ) );
		$epson_sdp = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/print-jobs/epson-sdp' ) );

		$this->assertSame( 401, $cloudprnt->get_status() );
		$this->assertSame( 'wcpos_print_job_invalid_token', $this->response_error_code( $cloudprnt ) );
		$this->assertSame( 401, $epson_sdp->get_status() );
		$this->assertSame( 'wcpos_print_job_invalid_token', $this->response_error_code( $epson_sdp ) );
	}

	/**
	 * Build a WCPOS REST request for the service matrix.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   REST route.
	 */
	private function service_request( string $method, string $path ): \WP_REST_Request {
		return 'POST' === $method ? $this->wp_rest_post_request( $path ) : $this->wp_rest_get_request( $path );
	}

	/**
	 * Read a response error code when present.
	 *
	 * @param \WP_REST_Response $response REST response.
	 */
	private function response_error_code( \WP_REST_Response $response ): string {
		$data = $response->get_data();

		return \is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
	}
}
