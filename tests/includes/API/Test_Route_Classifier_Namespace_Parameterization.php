<?php
/**
 * Tests for WCPOS REST namespace parameterization of the permission gate.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WP_REST_Response;
use WP_REST_Server;

/**
 * Namespace parameterization regression tests.
 *
 * @covers \WCPOS\WooCommercePOS\API::get_route_namespaces
 * @covers \WCPOS\WooCommercePOS\API::rest_pre_dispatch
 */
class Test_Route_Classifier_Namespace_Parameterization extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Add the future namespace before REST route registration.
	 */
	public function setUp(): void {
		add_filter( 'woocommerce_pos_rest_namespaces', array( $this, 'add_v2_namespace' ) );
		parent::setUp();
	}

	/**
	 * Remove the future namespace filter.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_rest_namespaces', array( $this, 'add_v2_namespace' ) );
		parent::tearDown();
	}

	/**
	 * Add wcpos/v2 to the registered WCPOS namespaces.
	 *
	 * @param string[] $namespaces REST namespaces.
	 *
	 * @return string[] REST namespaces.
	 */
	public function add_v2_namespace( array $namespaces ): array {
		$namespaces[] = 'wcpos/v2';

		return $namespaces;
	}

	/**
	 * Register dummy v2 routes after the WCPOS gate has captured its namespaces.
	 */
	public function rest_api_init(): void {
		parent::rest_api_init();

		foreach ( array( '/private', '/auth/test' ) as $route ) {
			register_rest_route(
				'wcpos/v2',
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'dummy_route_response' ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Return a successful dummy route response.
	 */
	public function dummy_route_response(): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Unauthenticated requests in an added namespace receive the baseline 401.
	 */
	public function test_added_namespace_unauthenticated_request_receives_gate_401(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/private' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] );
	}

	/**
	 * Logged-in users without POS access receive the baseline 403.
	 */
	public function test_added_namespace_logged_in_user_without_pos_access_receives_gate_403(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/private' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * Route exemptions declared by v1 controllers do not bleed into v2.
	 */
	public function test_v1_public_route_classification_does_not_bleed_into_added_namespace(): void {
		wp_set_current_user( 0 );

		$v1_response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/auth/test' ) );
		$v2_response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/auth/test' ) );

		$this->assertNotSame( 'woocommerce_pos_rest_unauthorized', $v1_response->get_data()['code'] ?? '' );
		$this->assertSame( 401, $v2_response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $v2_response->get_data()['code'] );
	}
}
