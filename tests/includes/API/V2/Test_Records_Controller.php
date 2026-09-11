<?php
/** Fiscal records REST tests. @package WCPOS\WooCommercePOS\Tests\API\V2 */
namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

class Test_Records_Controller extends WCPOS_REST_Unit_Test_Case {
	private function records( array $args = array() ) {
		$request = $this->wp_rest_get_request( '/wcpos/v2/records' );
		$request->set_query_params( $args );
		return $this->server->dispatch( $request );
	}

	public function test_list_filters_paging_headers_and_item(): void {
		$store = new Fiscal_Record_Store();
		$register = wp_generate_uuid4();
		$base = array( 'type' => 'sale', 'store_id' => 12, 'register_id' => $register, 'payload' => array( 'amount' => '12.00' ) );
		$one = $store->record( array_merge( $base, array( 'order_id' => 123, 'number' => 123 ) ) );
		$two = $store->record( array_merge( $base, array( 'order_id' => 124, 'number' => 124 ) ) );
		$response = $this->records( array( 'register_id' => $register, 'type' => 'sale', 'per_page' => 1, 'page' => 2 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $one ), $response->get_data() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( array( $two ), $this->records( array( 'order_id' => 124, 'after' => str_replace( ' ', 'T', $two['received_at_gmt'] ), 'before' => $two['received_at_gmt'] ) )->get_data() );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/' . $one['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $one, $response->get_data() );
	}

	public function test_list_filter_scopes_rows_and_totals(): void {
		$store = new Fiscal_Record_Store();
		$one = $store->record( array( 'type' => 'sale', 'order_id' => 125, 'number' => 125, 'store_id' => 12, 'payload' => array() ) );
		$store->record( array( 'type' => 'sale', 'order_id' => 126, 'number' => 126, 'store_id' => 13, 'payload' => array() ) );
		$filter = static function ( $args, $request ) {
			$args['store_id'] = array( 12 );
			return $args;
		};
		add_filter( 'woocommerce_pos_records_list_args', $filter, 10, 2 );
		try {
			$response = $this->records( array( 'store_id' => 13 ) );
			$this->assertSame( array( $one ), $response->get_data() );
			$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		} finally {
			remove_filter( 'woocommerce_pos_records_list_args', $filter );
		}
	}

	public function test_unknown_forbidden_and_write_routes(): void {
		$this->assertSame( 404, $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/999999999' ) )->get_status() );
		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/records' ) );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->records()->get_status() );
		$this->assertSame( 403, $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/1' ) )->get_status() );
	}

	public function test_invalid_filters_are_rejected(): void {
		foreach ( array( array( 'order_id' => '1x' ), array( 'store_id' => array( 12, 'x' ) ), array( 'register_id' => 'bad' ), array( 'session_id' => array() ), array( 'type' => 'bad' ), array( 'after' => '2026-02-30T12:00:00' ), array( 'before' => 'yesterday' ), array( 'page' => 0 ), array( 'per_page' => 201 ) ) as $args ) {
			$this->assertSame( 400, $this->records( $args )->get_status(), wp_json_encode( $args ) );
		}
	}
}
