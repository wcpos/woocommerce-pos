<?php
/** Registers REST tests. @package WCPOS\WooCommercePOS\Tests\API\V2 */
namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

class Test_Registers_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setUp(): void {
		parent::setUp();
		( new Register_Store() )->install();
	}

	private function post_register( array $fields ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/registers' );
		$request->set_body_params( $fields );
		return $this->server->dispatch( $request );
	}

	public function test_post_and_replay_return_created_then_updated_row(): void {
		$fields = array( 'id' => strtoupper( wp_generate_uuid4() ), 'name' => 'Front', 'platform' => 'ios', 'app_version' => 'test' );
		$first = $this->post_register( $fields );
		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( strtolower( $fields['id'] ), $first->get_data()['id'] );
		$fields['name'] = 'Back';
		$fields['store_id'] = 999;
		$again = $this->post_register( $fields );
		$this->assertSame( 200, $again->get_status() );
		$expected = $first->get_data();
		// A replay never renames: the name is set at creation, renamed only by PATCH.
		$expected['last_seen_at_gmt'] = $again->get_data()['last_seen_at_gmt'];
		$this->assertSame( $expected, $again->get_data() );
	}

	public function test_invalid_id_returns_400(): void {
		$this->assertSame( 400, $this->post_register( array( 'id' => 'bad', 'name' => 'Front' ) )->get_status() );
	}

	public function test_patch_requires_manager_and_list_defaults_to_active(): void {
		$id = wp_generate_uuid4();
		$this->post_register( array( 'id' => $id, 'name' => 'Front' ) );
		$request = $this->wp_rest_patch_request( '/wcpos/v2/registers/' . $id );
		$request->set_method( 'PATCH' );
		$request->set_body_params( array( 'name' => 'Back', 'status' => 'retired', 'default_float' => '12.50', 'store_id' => 99 ) );
		$cashier = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $cashier );
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
		wp_set_current_user( $this->user );
		$updated = $this->server->dispatch( $request );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'Back', $updated->get_data()['name'] );
		$this->assertSame( 'retired', $updated->get_data()['status'] );
		$this->assertSame( '12.5000', $updated->get_data()['default_float'] );
		$this->assertNull( $updated->get_data()['store_id'] );
		$list = $this->wp_rest_get_request( '/wcpos/v2/registers' );
		$this->assertNotContains( $id, array_column( $this->server->dispatch( $list )->get_data(), 'id' ) );
		$list->set_query_params( array( 'status' => 'all' ) );
		$this->assertContains( $id, array_column( $this->server->dispatch( $list )->get_data(), 'id' ) );
	}

	public function test_unknown_register_returns_404(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/' . wp_generate_uuid4() ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_register_not_found', $response->get_data()['code'] );
	}

	public function test_health_pos_user_receives_diagnostics(): void {
		$cashier = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $cashier );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/health' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'registers', $response->get_data() );
		$this->assertArrayHasKey( 'unregistered', $response->get_data() );
	}

	public function test_health_without_pos_access_returns_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/health' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_health_literal_is_not_an_item_id_and_is_protocol_exempt(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v2/registers/health', $routes );
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/wcpos/v2/registers/' ) && false !== strpos( $route, '(?P<id>' ) ) {
				$this->assertSame( 0, preg_match( '@^' . $route . '$@', '/wcpos/v2/registers/health' ) );
			}
		}
		$controller = new \WCPOS\WooCommercePOS\API\V2\Registers_Controller();
		$this->assertContains( '/wcpos/v2/registers/health', $controller->wcpos_route_classifications()['protocol_exempt'] );
	}

	public function test_health_includes_retired_registers_and_applies_store_scope(): void {
		$store = new Register_Store();
		$id = wp_generate_uuid4();
		$store->upsert( array( 'id' => $id, 'name' => 'Retired', 'store_id' => 123 ) );
		$store->update( $id, array( 'status' => 'retired' ) );
		$store->upsert( array( 'id' => wp_generate_uuid4(), 'name' => 'Other store', 'store_id' => 456 ) );
		$scope = function ( $args, $request ) {
			$this->assertSame( 'all', $args['status'] );
			$this->assertSame( '/wcpos/v2/registers/health', $request->get_route() );
			$args['store_id'] = 123;
			return $args;
		};
		add_filter( 'woocommerce_pos_registers_list_args', $scope, 10, 2 );
		try {
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/health' ) );
		} finally {
			remove_filter( 'woocommerce_pos_registers_list_args', $scope );
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $id ), array_column( $response->get_data()['registers'], 'id' ) );
	}

	public function test_health_options_describes_diagnostics_not_register_item(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/registers/health' );
		$request->set_method( 'OPTIONS' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$properties = $response->get_data()['schema']['properties'];
		$this->assertArrayHasKey( 'window_days', $properties );
		$this->assertArrayHasKey( 'registers', $properties );
		$this->assertArrayHasKey( 'truncated', $properties );
		$this->assertArrayNotHasKey( 'name', $properties );
		$this->assertArrayNotHasKey( 'status', $properties );
	}

	public function test_health_scope_keeps_global_known_ids_and_filters_order_stores(): void {
		$id = wp_generate_uuid4();
		$other = wp_generate_uuid4();
		$unknown = wp_generate_uuid4();
		$store = new Register_Store();
		$store->upsert( array( 'id' => $id, 'name' => 'Front', 'store_id' => 1 ) );
		$store->upsert( array( 'id' => $other, 'name' => 'Moved till', 'store_id' => 2 ) );
		foreach ( array( array( $id, 1 ), array( $id, 2 ), array( $other, 1 ), array( $unknown, 2 ) ) as list( $register, $store_id ) ) {
			$order = new \WC_Order();
			$order->update_meta_data( '_wcpos_register', $register );
			$order->update_meta_data( '_pos_store', $store_id );
			$order->save();
		}
		$scope = static function ( $args ) {
			$args['store_id'] = 1;
			return $args;
		};
		add_filter( 'woocommerce_pos_registers_list_args', $scope );
		try {
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/health' ) );
		} finally {
			remove_filter( 'woocommerce_pos_registers_list_args', $scope );
		}
		$this->assertSame( 200, $response->get_status() );
		$report = $response->get_data();
		$this->assertSame( array( $id ), array_column( $report['registers'], 'id' ) );
		$this->assertSame( 1, $report['registers'][0]['orders'] );
		$this->assertNotContains( $other, array_column( $report['unregistered'], 'register_id' ) );
		$this->assertNotContains( $unknown, array_column( $report['unregistered'], 'register_id' ) );
	}
}
