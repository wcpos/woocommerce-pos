<?php
/**
 * Tests for WCPOS REST route classification.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionClass;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\API\Route_Classifier;
use WP_REST_Server;

/**
 * Legacy filtered auth controller without route classification metadata.
 */
class Route_Classifier_Legacy_Auth_Controller {
	/**
	 * Register the historical public auth route.
	 */
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
 * Filtered auth controller with explicit route classification metadata.
 */
class Route_Classifier_Metadata_Auth_Controller extends Route_Classifier_Legacy_Auth_Controller {
	/**
	 * Explicitly opt out of the historical public auth classification.
	 *
	 * @return array<string, string[]> Route classifications.
	 */
	public function wcpos_route_classifications(): array {
		return array( 'public' => array() );
	}
}

/**
 * API subclass that exposes the built classifier to tests.
 */
class Route_Classifier_Test_API extends API {
	/**
	 * Get the route classifier built during registration.
	 */
	public function get_route_classifier(): Route_Classifier {
		return $this->route_classifier;
	}
}

/**
 * Route classifier data-level regression tests.
 *
 * @covers \WCPOS\WooCommercePOS\API\Route_Classifier
 */
class Test_Route_Classifier extends WCPOS_REST_Unit_Test_Case {
	/**
	 * API instance initialized with the registered routes.
	 *
	 * @var Route_Classifier_Test_API
	 */
	private $api;

	/**
	 * Register the testable API instance.
	 */
	public function rest_api_init(): void {
		$this->api = new Route_Classifier_Test_API();
	}

	/**
	 * Filtered legacy controllers retain the historical route exemptions.
	 */
	public function test_filtered_legacy_controller_without_classifications_keeps_public_exemption(): void {
		$replace_auth = function ( array $controllers ): array {
			$controllers['auth'] = Route_Classifier_Legacy_Auth_Controller::class;
			return $controllers;
		};
		add_filter( 'woocommerce_pos_rest_api_controllers', $replace_auth );
		$api = new Route_Classifier_Test_API();
		remove_filter( 'woocommerce_pos_rest_api_controllers', $replace_auth );
		wp_set_current_user( 0 );

		$result = $api->rest_pre_dispatch( null, $this->server, $this->wp_rest_get_request( '/wcpos/v1/auth/test' ) );

		$this->assertNull( $result );
	}

	/**
	 * Filtered controllers with classification metadata remain authoritative.
	 */
	public function test_filtered_controller_with_classifications_does_not_receive_legacy_fallback(): void {
		$replace_auth = function ( array $controllers ): array {
			$controllers['auth'] = Route_Classifier_Metadata_Auth_Controller::class;
			return $controllers;
		};
		add_filter( 'woocommerce_pos_rest_api_controllers', $replace_auth );
		$api = new Route_Classifier_Test_API();
		remove_filter( 'woocommerce_pos_rest_api_controllers', $replace_auth );
		wp_set_current_user( 0 );

		$result = $api->rest_pre_dispatch( null, $this->server, $this->wp_rest_get_request( '/wcpos/v1/auth/test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * The built classifier contains the core namespaces and current literal route groups.
	 */
	public function test_built_classifications_match_current_permission_gate_routes_exactly(): void {
		$classifier      = $this->api->get_route_classifier();
		$reflection      = new ReflectionClass( $classifier );
		$namespaces      = $reflection->getProperty( 'namespaces' );
		$classifications = $reflection->getProperty( 'classifications' );
		$namespaces->setAccessible( true );
		$classifications->setAccessible( true );

		$built_namespaces     = $namespaces->getValue( $classifier );
		$built_classifications = $classifications->getValue( $classifier );

		$this->assertSame( array( 'wcpos/v1', 'wcpos/v2' ), $built_namespaces );
		$this->assertSame(
			array( '/wcpos/v1/auth/test', '/wcpos/v1/auth/refresh' ),
			$built_classifications['public']
		);
		$this->assertSame(
			array( '/wcpos/v1/print-jobs/cloudprnt', '/wcpos/v1/print-jobs/epson-sdp' ),
			$built_classifications['printer_token']
		);
		$this->assertSame(
			array( '/wcpos/v1/sync/uuid/backfill', '/wcpos/v1/sync/orders/index/backfill', '/wcpos/v1/sync/integrity/rebuild' ),
			$built_classifications['admin_op']
		);
		$this->assertSame(
			array( '/wcpos/v1/receipts/' ),
			$built_classifications['permission_error_passthrough']
		);
		$this->assertSame(
			array( '/wcpos/v1/sync/' ),
			$built_classifications['rewrite_exempt']
		);
		$this->assertSame(
			array( 'public', 'printer_token', 'admin_op', 'permission_error_passthrough', 'rewrite_exempt' ),
			array_keys( $built_classifications )
		);
	}

	/**
	 * The core gate covers v2 before any v2 routes are registered.
	 */
	public function test_unregistered_v2_route_receives_anonymous_gate_401(): void {
		wp_set_current_user( 0 );

		$this->assertSame( array(), $this->server->get_routes( 'wcpos/v2' ) );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/anything' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] );
	}
}
