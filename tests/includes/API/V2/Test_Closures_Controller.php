<?php
/**
 * Closure REST tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** HTTP identity, permissions, paging and immutable corrections. */
class Test_Closures_Controller extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Grant write capabilities for the happy-path fixture. */
	public function setUp(): void {
		parent::setUp();
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_closures' );
	}

	/** Dispatch a write.
	 *
	 * @param string $route Route.
	 * @param array  $body Body.
	 */
	private function post( string $route, array $body ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/' . $route );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/** Dispatch a read.
	 *
	 * @param string $route Route.
	 * @param array  $args Filters.
	 */
	private function get( string $route, array $args = array() ) {
		$request = $this->wp_rest_get_request( '/wcpos/v2/' . $route );
		$request->set_query_params( $args );
		return $this->server->dispatch( $request );
	}

	/** Build a REST submission.
	 *
	 * @param array $session Session row.
	 * @param int   $number Closure number.
	 */
	private function body( array $session, int $number = 1 ): array {
		$body = $this->closure_fields( $session, $number );
		foreach ( array( 'opened_at', 'closed_at', 'printed_at' ) as $key ) {
			$body[ $key ] = null === $body[ $key . '_gmt' ] ? null : str_replace( ' ', 'T', $body[ $key . '_gmt' ] ) . 'Z';
			unset( $body[ $key . '_gmt' ] );
		}
		return $body;
	}

	/** Create replay print and recount. */
	public function test_create_replay_print_and_recount(): void {
		$session = $this->closure_session();
		$body = $this->body( $session );
		$response = $this->post( 'closures', $body );
		$this->assertSame( 201, $response->get_status() );
		$row = $response->get_data();
		$this->assertSame( $row, $this->post( 'closures', array( 'id' => $row['id'] ) )->get_data() );
		$this->assertSame( 200, $this->post( 'closures', array( 'id' => $row['id'] ) )->get_status() );
		$this->assertSame( $row + array( 'corrections' => array() ), $this->get( 'closures/' . $row['id'] )->get_data() );
		$this->assertSame( $row, $this->get( 'closures/last', array( 'register_id' => $session['register_id'] ) )->get_data() );
		$this->assertSame( $row, $this->get( 'closures/LAST', array( 'register_id' => $session['register_id'] ) )->get_data() );
		foreach ( array( 1, 2 ) as $count ) {
			$printed = $this->post( 'closures/' . $row['id'] . '/print', array() )->get_data();
			$this->assertSame( $count, $printed['print_count'] );
			$this->assertNotNull( $printed['last_printed_at_gmt'] );
			unset( $printed['print_count'], $printed['last_printed_at_gmt'] );
			$this->assertSame( array_diff_key( $row, array_flip( array( 'print_count', 'last_printed_at_gmt' ) ) ), $printed );
		}
		$this->assertSame( 3, $this->post( 'closures/' . $row['id'] . '/PRINT', array() )->get_data()['print_count'] );
		$recount = array(
			'id' => wp_generate_uuid4(),
			'counted' => array( 'cash' => '97' ),
			'reason' => 'Second count',
		);
		$first = $this->post( 'closures/' . $row['id'] . '/recount', $recount );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( $first->get_data(), $this->post( 'closures/' . $row['id'] . '/ReCoUnT', $recount )->get_data() );
		$this->assertSame( $recount['id'], $first->get_data()['source_id'] );
		$this->assertSame( $row['id'], $first->get_data()['closure_id'] );
		$this->assertSame( array( 'cash' => '-3.0000' ), $first->get_data()['payload']['variance'] );
		$this->assertSame(
			array( $first->get_data() ),
			$this->get(
				'records',
				array(
					'closure_id' => $row['id'],
					'type' => 'recount',
				)
			)->get_data()
		);
		$recount['counted']['cash'] = '800';
		$this->assertSame( $first->get_data(), $this->post( 'closures/' . $row['id'] . '/recount', $recount )->get_data() );
		$body['id'] = wp_generate_uuid4();
		$this->assertSame( 409, $this->post( 'closures', $body )->get_status() );
	}

	/** The session stamp wins, and filtering uses it rather than the closing instant. */
	public function test_closure_business_day_copies_session_and_filters_dates(): void {
		global $wpdb;
		$session = $this->closure_session();
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => '2026-09-10' ), array( 'id' => $session['id'] ) );
		$body = $this->body( $session );
		$body['business_day'] = '2026-09-11';
		$row = $this->post( 'closures', $body )->get_data();
		$this->assertSame( '2026-09-10', $row['business_day'] );
		$args = array(
			'register_id' => $session['register_id'],
			'after' => '2026-09-10',
			'before' => '2026-09-10',
		);
		$this->assertSame( array( $row['id'] ), array_column( $this->get( 'closures', $args )->get_data(), 'id' ) );
		$args['after'] = '2026-09-11T00:00:00Z';
		$args['before'] = '2026-09-12T00:00:00Z';
		$this->assertSame( array(), $this->get( 'closures', $args )->get_data() );
		$wpdb->update( ( new Closure_Store() )->table_name(), array( 'business_day' => null ), array( 'id' => $row['id'] ) );
		$this->assertSame( array( $row['id'] ), array_column( $this->get( 'closures', $args )->get_data(), 'id' ) );
	}

	/** A credential override must authenticate a capable manager without changing the actor. */
	public function test_recount_manager_approval_refusals_and_actor_stamps(): void {
		$row = $this->post( 'closures', $this->body( $this->closure_session() ) )->get_data();
		$manager = self::factory()->user->create_and_get(
			array(
				'role' => 'subscriber',
				'user_pass' => 'recount-test-password',
				'display_name' => 'Recount manager',
			)
		);
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		$cashier = self::factory()->user->create_and_get(
			array(
				'role' => 'subscriber',
				'user_pass' => 'cashier-test-password',
			)
		);
		$cashier->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $cashier->ID );
		$body = array(
			'id' => wp_generate_uuid4(),
			'counted' => array( 'cash' => '98' ),
			'reason' => 'Recount',
		);
		$route = 'closures/' . $row['id'] . '/recount';
		$this->assertSame( 403, $this->post( $route, $body )->get_status() );
		foreach ( array(
			array(
				array(
					'username' => $manager->user_login,
					'password' => 'wrong',
				),
				'wcpos_recount_approval_invalid',
			),
			array(
				array(
					'username' => $cashier->user_login,
					'password' => 'cashier-test-password',
				),
				'wcpos_recount_approval_forbidden',
			),
			array(
				array(
					'username' => array(),
					'password' => 'wrong',
				),
				'wcpos_recount_approval_invalid',
			),
		) as $case ) {
			$response = $this->post( $route, $body + array( 'approval' => $case[0] ) );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( $case[1], $response->get_data()['code'] );
		}
		$body['approval'] = array(
			'username' => $manager->user_login,
			'password' => 'recount-test-password',
		);
		$response = $this->post( $route, $body );
		$this->assertSame( 200, $response->get_status() );
		$record = $response->get_data();
		$this->assertArrayNotHasKey( 'variance', $record['payload'] );
		$this->assertSame( $cashier->ID, $record['cashier_id'] );
		$this->assertSame( $manager->ID, $record['approver_id'] );
		$this->assertSame( $cashier->ID, get_current_user_id() );
		$this->assertStringNotContainsString( 'recount-test-password', wp_json_encode( $record ) );
		$correction = ( new Closure_Store() )->corrections_for( $row['id'] )[0];
		$this->assertSame(
			array(
				'id' => $manager->ID,
				'name' => 'Recount manager',
			),
			$correction['approver']
		);
		$this->assertSame( array( 'cash' => '-2.0000' ), $correction['figures']['variance'] );
		$this->assertSame( $cashier->ID, $correction['actor']['id'] );
		$this->assertSame( $record, $this->post( $route, $body )->get_data() );
		$cashier->remove_cap( 'access_woocommerce_pos' );
		wp_set_current_user( 0 );
		wp_set_current_user( $cashier->ID );
		$this->assertSame( 403, $this->post( $route, $body )->get_status() );
	}

	/** All four correction rows are shared by detail and receipt; list counts are grouped. */
	public function test_closure_corrections_detail_list_and_receipt_contract(): void {
		global $wpdb;
		$session = $this->closure_session();
		$movement = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'Opening adjustment',
			'created_at' => '2026-09-11T09:00:00Z',
		);
		$this->assertSame( 201, $this->post( 'movements', $movement )->get_status() );
		$row = $this->post( 'closures', $this->body( $session ) )->get_data();
		$empty = $this->post( 'closures', $this->body( $this->closure_session( $session['register_id'] ), 2 ) )->get_data();
		$this->assertSame( array(), $this->get( 'closures/' . $empty['id'] )->get_data()['corrections'] );
		$records = new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store();
		$order = $this->closure_ledger( $session );
		$order->update_meta_data( \WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store::META_KEY_CREATED_AT, $row['received_at_gmt'] );
		$order->save();
		\WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers::instance()->record_sale( $order, array(), 987654 );
		$this->assertSame(
			201,
			$this->post(
				'movements',
				array_merge(
					$movement,
					array(
						'id' => wp_generate_uuid4(),
						'type' => 'paid_out',
						'amount' => '3.125',
						'reason' => 'Petty cash',
					)
				)
			)->get_status()
		);
		$this->assertSame(
			201,
			$this->post(
				'movements',
				array_merge(
					$movement,
					array(
						'id' => wp_generate_uuid4(),
						'type' => 'void',
						'amount' => '0',
						'voids' => $movement['id'],
						'reason' => 'Reverse float',
					)
				)
			)->get_status()
		);
		$this->post(
			'closures/' . $row['id'] . '/recount',
			array(
				'id' => wp_generate_uuid4(),
				'counted' => array( 'cash' => '105' ),
				'reason' => 'Second count',
			)
		);
		$raw = array_reverse( $records->list( array( 'closure_id' => $row['id'] ) ) );
		$this->assertCount( 4, $raw );
		// Put the last record first by time; the others tie and must retain id order.
		foreach ( $raw as $index => $record ) {
			$wpdb->update( $records->table_name(), array( 'received_at_gmt' => 3 === $index ? '2026-09-12 09:00:00' : '2026-09-12 10:00:00' ), array( 'id' => $record['id'] ) );
		}
		$corrections = $this->get( 'closures/' . $row['id'] )->get_data()['corrections'];
		$this->assertSame( array( $raw[3]['id'], $raw[0]['id'], $raw[1]['id'], $raw[2]['id'] ), array_column( $corrections, 'id' ) );
		$this->assertSame(
			array(
				'counted' => array( 'cash' => '105.0000' ),
				'variance' => array( 'cash' => '-2.0000' ),
			),
			$corrections[0]['figures']
		);
		$this->assertSame(
			array(
				'expected_delta' => array(
					'cash' => '40.0000',
					'card' => '30.0000',
				),
				'sales_delta' => '80.0000',
				'refunds_delta' => '10.0000',
			),
			$corrections[1]['figures']
		);
		$this->assertSame( array( 'cash_delta' => '-3.1250' ), $corrections[2]['figures'] );
		$this->assertSame( array( 'cash_delta' => '-7.0000' ), $corrections[3]['figures'] );
		$this->assertSame( 'Petty cash', $corrections[2]['reason'] );
		$this->assertSame( '2026-09-12T09:00:00Z', $corrections[0]['created_at'] );
		$this->assertNull( $corrections[0]['approver'] );
		$this->assertSame( get_current_user_id(), $corrections[0]['actor']['id'] );
		$queries = array();
		$observe = static function ( $sql ) use ( &$queries ) {
			if ( false !== strpos( $sql, 'wcpos_fiscal_records' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$listed = $this->get( 'closures', array( 'register_id' => $session['register_id'] ) )->get_data();
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame(
			array(
				$empty['id'] => 0,
				$row['id'] => 4,
			),
			array_column( $listed, 'corrections_count', 'id' )
		);
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'GROUP BY closure_id', $queries[0] );
		$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/0' );
		$request->set_param( 'document', 'closure:' . $row['id'] );
		$this->assertSame( $corrections, $this->server->dispatch( $request )->get_data()['data']['closure']['corrections'] );
		$request->set_param( 'document', 'closure:' . $empty['id'] );
		$this->assertSame( array(), $this->server->dispatch( $request )->get_data()['data']['closure']['corrections'] );
		$tree = \WCPOS\WooCommercePOS\Services\Receipt_Data_Schema::get_field_tree( 'closure' );
		$this->assertSame( 'array', $tree['closure']['fields']['corrections']['type'] );
		$this->assertArrayHasKey( 'figures', $tree['closure']['fields']['corrections']['fields'] );
		$this->assertSame( $row, ( new Closure_Store() )->get( $row['id'] ) );
		$order->delete( true );
	}

	/** Permissions and validation. */
	public function test_permissions_and_validation(): void {
		$session = $this->closure_session();
		$body = $this->body( $session );
		$this->assertSame( 409, $this->post( 'closures', array_merge( $body, array( 'session_id' => wp_generate_uuid4() ) ) )->get_status() );
		$open = $this->closure_session( null, 'open' );
		$this->assertSame( 409, $this->post( 'closures', $this->body( $open ) )->get_status() );
		foreach ( array(
			'id' => 'bad',
			'session_id' => 'bad',
			'number' => 0,
			'closed_at' => '2026-02-30T12:00:00Z',
			'opened_at' => array(),
			'counted' => array( 'cash' => '1e2' ),
			'period_sales_total' => 'NaN',
			'unsynced_count' => -1,
			'breakdowns' => 'bad',
		) as $key => $value ) {
			$this->assertSame( 400, $this->post( 'closures', array_merge( $body, array( $key => $value ) ) )->get_status(), $key );
		}
		$row = $this->post( 'closures', $body )->get_data();
		$this->assertSame( 400, $this->get( 'closures', array( 'after' => array() ) )->get_status() );
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );
		// Reading a closure back is a report, so WCPOS access alone is not enough.
		$this->assertSame( 403, $this->get( 'closures' )->get_status() );
		$user->add_cap( 'view_woocommerce_pos_reports' );
		// wp_set_current_user() is a no-op for the same id; re-set so the cached caps reload.
		wp_set_current_user( 0 );
		wp_set_current_user( $user->ID );
		$this->assertSame( 200, $this->get( 'closures' )->get_status() );
		$this->assertSame( 403, $this->post( 'closures', $body )->get_status() );
		$user->add_cap( 'manage_woocommerce_pos_cash' );
		$this->assertSame(
			403,
			$this->post(
				'closures/' . $row['id'] . '/recount',
				array(
					'id' => wp_generate_uuid4(),
					'counted' => array( 'cash' => '100' ),
					'reason' => 'Count',
				)
			)->get_status()
		);
		$this->assertSame(
			403,
			$this->post(
				'closures/' . $row['id'] . '/RECOUNT',
				array(
					'id' => wp_generate_uuid4(),
					'counted' => array( 'cash' => '100' ),
					'reason' => 'Count',
				)
			)->get_status()
		);
		$user->remove_cap( 'access_woocommerce_pos' );
		// wp_set_current_user() is a no-op for the same id; re-set so the cached caps reload.
		wp_set_current_user( 0 );
		wp_set_current_user( $user->ID );
		$this->assertSame( 403, $this->get( 'closures' )->get_status() );
		$this->assertSame( 403, $this->post( 'closures/' . $row['id'] . '/print', array() )->get_status() );
	}

	/** Reading closures back is a report: a blind cashier is refused. */
	public function test_closure_reads_require_the_reports_capability(): void {
		$session = $this->closure_session();
		$created = $this->post( 'closures', $this->body( $session ) );
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];
		$blind = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$blind->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $blind->ID );
		foreach ( array( 'closures', 'closures/' . $id, 'closures/last' ) as $route ) {
			$response = $this->get( $route, array( 'register_id' => $session['register_id'] ) );
			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 'rest_forbidden', $response->get_data()['code'], $route );
		}
		$blind->add_cap( 'view_woocommerce_pos_reports' );
		// wp_set_current_user() is a no-op for the same id; re-set so the cached caps reload.
		wp_set_current_user( 0 );
		wp_set_current_user( $blind->ID );
		$this->assertSame( 200, $this->get( 'closures/' . $id )->get_status() );
	}

	/** An id-only replay must not become a back door onto a closure's figures. */
	public function test_closure_replay_redacts_figures_without_the_reports_capability(): void {
		$session = $this->closure_session();
		$body = $this->body( $session );
		$id = $this->post( 'closures', $body )->get_data()['id'];
		$cashier = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$cashier->add_cap( 'access_woocommerce_pos' );
		$cashier->add_cap( 'manage_woocommerce_pos_cash' );
		wp_set_current_user( $cashier->ID );
		$replay = $this->post( 'closures', array( 'id' => $id ) );
		$this->assertSame( 200, $replay->get_status() );
		$row = $replay->get_data();
		foreach ( array( 'expected', 'till_expected', 'variance' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $row, $field );
		}
		// The counters the till needs to mint the next number still come back.
		$this->assertSame( 1, $row['number'] );
		$this->assertArrayHasKey( 'perpetual_sales_total', $row );
		$cashier->add_cap( 'view_woocommerce_pos_reports' );
		// wp_set_current_user() is a no-op for the same id; re-set so the cached caps reload.
		wp_set_current_user( 0 );
		wp_set_current_user( $cashier->ID );
		$this->assertArrayHasKey( 'expected', $this->post( 'closures', array( 'id' => $id ) )->get_data() );
	}

	/** List filters paging and register counters. */
	public function test_list_filters_paging_and_register_counters(): void {
		$a = $this->closure_session();
		$first = $this->post( 'closures', $this->body( $a ) )->get_data();
		$b = $this->closure_session( $a['register_id'] );
		$body = $this->body( $b, 2 );
		$body['closed_at'] = '2026-09-12T12:00:00Z';
		$second = $this->post( 'closures', $body )->get_data();
		foreach ( array(
			1 => $second['id'],
			2 => $first['id'],
		) as $page => $id ) {
			$this->assertSame(
				array( $id ),
				array_column(
					$this->get(
						'closures',
						array(
							'register_id' => $a['register_id'],
							'page' => $page,
							'per_page' => 1,
						)
					)->get_data(),
					'id'
				)
			);
		}
		$this->assertSame(
			array( $second['id'] ),
			array_column(
				$this->get(
					'closures',
					array(
						'register_id' => $a['register_id'],
						'after' => '2026-09-12T00:00:00Z',
						'before' => '2026-09-13T00:00:00Z',
						'store_id' => 456,
					)
				)->get_data(),
				'id'
			)
		);
		$scope = static function ( $args ) {
			$args['store_id'] = 789;
			return $args;
		};
		add_filter( 'woocommerce_pos_closures_list_args', $scope );
		$this->assertSame( array(), $this->get( 'closures' )->get_data() );
		remove_filter( 'woocommerce_pos_closures_list_args', $scope );
		$this->assertSame( 2, $this->get( 'registers/' . $a['register_id'] )->get_data()['counters']['last_closure_number'] );
		$register_counters = array_column( $this->get( 'registers' )->get_data(), 'counters', 'id' );
		$this->assertSame( 2, $register_counters[ $a['register_id'] ]['last_closure_number'] );
		foreach ( array( array( 'per_page' => 101 ), array( 'page' => 0 ), array( 'register_id' => 'bad' ), array( 'after' => 'bad' ) ) as $args ) {
			$this->assertSame( 400, $this->get( 'closures', $args )->get_status() );
		}
	}
	/** Offline movements before counting are accepted; later ones remain forbidden. */
	public function test_late_movement_cutoff_and_correction(): void {
		$session = $this->closure_session();
		$movement = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'Offline',
			'created_at' => '2026-09-11T10:59:59Z',
		);
		$this->assertSame( 201, $this->post( 'movements', $movement )->get_status() );
		$closure = $this->post( 'closures', $this->body( $session ) )->get_data();
		$movement['id'] = wp_generate_uuid4();
		$this->assertSame( 201, $this->post( 'movements', $movement )->get_status() );
		$this->assertSame( 200, $this->post( 'movements', $movement )->get_status() );
		$records = ( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_movement',
				'closure_id' => $closure['id'],
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $movement['id'], $records[0]['source_id'] );
		$this->assertSame( $closure, ( new Closure_Store() )->get( $closure['id'] ) );
		foreach ( array( '2026-09-11T11:00:00Z', '2026-09-11T12:00:00Z' ) as $at ) {
			$movement['id'] = wp_generate_uuid4();
			$movement['created_at'] = $at;
			$this->assertSame( 409, $this->post( 'movements', $movement )->get_status() );
		}
	}
	/** Losing a late correction must not acknowledge and retain its movement. */
	public function test_failed_late_correction_rolls_back_movement(): void {
		global $wpdb;
		$session = $this->closure_session();
		$closure = $this->post( 'closures', $this->body( $session ) )->get_data();
		$movement = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_out',
			'amount' => '7',
			'reason' => 'Offline',
			'created_at' => '2026-09-11T10:59:59Z',
		);
		$table = ( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->table_name();
		$fail = static function ( $sql ) use ( $table ) {
			return 0 === strpos( $sql, 'INSERT INTO `' . $table . '`' ) ? 'INVALID CORRECTION INSERT' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$this->assertSame( 500, $this->post( 'movements', $movement )->get_status() );
			$this->assertNull( ( new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store() )->get( $movement['id'] ) );
			$this->assertSame( $closure, ( new Closure_Store() )->get( $closure['id'] ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}
}
