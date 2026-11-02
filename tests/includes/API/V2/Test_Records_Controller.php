<?php
/**
 * Records REST tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Store_Reach;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Records routing, permissions and store reach. */
class Test_Records_Controller extends WCPOS_REST_Unit_Test_Case {
	/** Read fiscal records with query filters.
	 *
	 * @param array $args Query filters.
	 */
	private function records( array $args = array() ) {
		$request = $this->wp_rest_get_request( '/wcpos/v2/records' );
		$request->set_query_params( $args );
		return $this->server->dispatch( $request );
	}

	/** Audit reads return stored bytes, without rendering labels. */
	public function test_record_route_returns_stored_payload_without_i18n(): void {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();
		\WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture::sale( $order );
		$row = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/' . $row['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $row['payload'], $response->get_data()['payload'] );
		$this->assertArrayNotHasKey( 'i18n', $response->get_data()['payload'] );
	}

	/** List filters paging headers and item. */
	public function test_list_filters_paging_headers_and_item(): void {
		$store = new Fiscal_Record_Store();
		$register = wp_generate_uuid4();
		$base = array(
			'type' => 'sale',
			'store_id' => 12,
			'register_id' => $register,
			'payload' => array( 'amount' => '12.00' ),
		);
		$one = $store->record(
			array_merge(
				$base,
				array(
					'order_id' => 123,
					'number' => 123,
					'source_id' => wp_generate_uuid4(),
				)
			)
		);
		$two = $store->record(
			array_merge(
				$base,
				array(
					'order_id' => 124,
					'number' => 124,
					'source_id' => wp_generate_uuid4(),
				)
			)
		);
		$response = $this->records(
			array(
				'register_id' => $register,
				'type' => 'sale',
				'per_page' => 1,
				'page' => 2,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $one ), $response->get_data() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame(
			array( $two ),
			$this->records(
				array(
					'order_id' => 124,
					'after' => str_replace( ' ', 'T', $two['received_at_gmt'] ),
					'before' => $two['received_at_gmt'],
				)
			)->get_data()
		);
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/' . $one['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $one, $response->get_data() );
	}

	/** List filter scopes rows and totals. */
	public function test_list_filter_scopes_rows_and_totals(): void {
		$store = new Fiscal_Record_Store();
		$one = $store->record(
			array(
				'type' => 'sale',
				'source_id' => wp_generate_uuid4(),
				'order_id' => 125,
				'number' => 125,
				'store_id' => 12,
				'payload' => array(),
			)
		);
		$store->record(
			array(
				'type' => 'sale',
				'source_id' => wp_generate_uuid4(),
				'order_id' => 126,
				'number' => 126,
				'store_id' => 13,
				'payload' => array(),
			)
		);
		$filter = static function ( $args, $request ) {
			$args['store_id'] = array( 12 );
			return $args;
		};
		add_filter( 'wcpos_records_list_args', $filter, 10, 2 );
		try {
			$response = $this->records( array( 'store_id' => 13 ) );
			$this->assertSame( array( $one ), $response->get_data() );
			$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		} finally {
			remove_filter( 'wcpos_records_list_args', $filter );
		}
	}

	/** Unknown forbidden and write routes. */
	public function test_unknown_forbidden_and_write_routes(): void {
		$this->assertSame( 404, $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/999999999' ) )->get_status() );
		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/records' ) );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->records()->get_status() );
		$this->assertSame( 403, $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/1' ) )->get_status() );
	}

	/** Invalid filters are rejected. */
	public function test_invalid_filters_are_rejected(): void {
		foreach ( array( array( 'order_id' => '1x' ), array( 'store_id' => array( 12, 'x' ) ), array( 'register_id' => 'bad' ), array( 'session_id' => array() ), array( 'type' => 'bad' ), array( 'after' => '2026-02-30T12:00:00' ), array( 'before' => 'yesterday' ), array( 'page' => 0 ), array( 'per_page' => 201 ) ) as $args ) {
			$this->assertSame( 400, $this->records( $args )->get_status(), wp_json_encode( $args ) );
		}
	}
	/** Records and their totals deny both disjoint and unreadable reach. */
	public function test_record_item_outside_store_reach_reads_as_absent(): void {
		foreach ( array( array( 12, 13 ), array( null, 'invalid' ) ) as list( $store_id, $allowed ) ) {
			// Arrange.
			$row = ( new Fiscal_Record_Store() )->record(
				array(
					'type' => 'sale',
					'source_id' => wp_generate_uuid4(),
					'order_id' => null === $store_id ? 128 : 127,
					'number' => null === $store_id ? 128 : 127,
					'store_id' => $store_id,
					'payload' => array(),
				)
			);
			$filter = static function () use ( $allowed ) {
				return array( 'store_id' => array( $allowed ) );
			};
			add_filter( Store_Reach::FILTER, $filter );
			try {
				// Act: fiscal record IDs are numeric, unlike the till UUID routes.
				$absent = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/999999999' ) );
				$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/' . $row['id'] ) );
				$list = $this->records();
				// Assert.
				$this->assertSame( $absent->get_status(), $response->get_status() );
				$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
				$this->assertSame( 200, $list->get_status() );
				$this->assertSame( array(), $list->get_data() );
				$this->assertSame( '0', $list->get_headers()['X-WP-Total'] );
			} finally {
				remove_filter( Store_Reach::FILTER, $filter );
			}
		}
	}
	/** Unassigned record detail, rows and total require the explicit key. */
	public function test_unassigned_record_is_reachable_only_with_the_unassigned_key(): void {
		// Arrange.
		$row = ( new Fiscal_Record_Store() )->record(
			array(
				'type' => 'sale',
				'source_id' => wp_generate_uuid4(),
				'order_id' => 123,
				'number' => 123,
				'store_id' => null,
				'payload' => array( 'amount' => '12.00' ),
			)
		);
		foreach ( array( true, false ) as $unassigned ) {
			$filter = static function () use ( $unassigned ) {
				return array( 'store_id' => array( 789 ) ) + ( $unassigned ? array( 'unassigned' => true ) : array() );
			};
			add_filter( Store_Reach::FILTER, $filter );
			try {
				// Act.
				$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/' . $row['id'] ) );
				$list = $this->records();
				// Assert.
				$this->assertSame( $unassigned ? 200 : 404, $response->get_status() );
				$this->assertSame( 200, $list->get_status() );
				$this->assertSame( $unassigned ? array( $row ) : array(), $list->get_data() );
				$this->assertSame( $unassigned ? '1' : '0', $list->get_headers()['X-WP-Total'] );
				if ( ! $unassigned ) {
					$absent = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/records/999999999' ) );
					$this->assertSame( $absent->get_status(), $response->get_status() );
					$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
				}
			} finally {
				remove_filter( Store_Reach::FILTER, $filter );
			}
		}
	}
}
