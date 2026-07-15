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
	 * The built v1 classifier contains exactly the current literal route groups.
	 */
	public function test_built_v1_classifications_match_current_permission_gate_routes_exactly(): void {
		$classifier      = $this->api->get_route_classifier();
		$reflection      = new ReflectionClass( $classifier );
		$namespaces      = $reflection->getProperty( 'namespaces' );
		$classifications = $reflection->getProperty( 'classifications' );
		$namespaces->setAccessible( true );
		$classifications->setAccessible( true );

		$built_namespaces     = $namespaces->getValue( $classifier );
		$built_classifications = $classifications->getValue( $classifier );

		$this->assertSame( array( 'wcpos/v1' ), $built_namespaces );
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
}
