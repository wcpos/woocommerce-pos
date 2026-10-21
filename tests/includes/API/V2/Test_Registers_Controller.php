<?php
/**
 * Registers REST tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Option_Register_Repository;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Store_Reach;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;

/** Registers routing, permissions and store reach. Tests of more than one register bind an in-memory repository, as Pro binds its own. */
class Test_Registers_Controller extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Create a register through REST.
	 *
	 * @param array $fields Register fields.
	 */
	private function post_register( array $fields ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/registers' );
		$request->set_body_params( $fields );
		return $this->server->dispatch( $request );
	}

	/** Free holds one register: a manager's create is refused before its body is read, and nothing is stored. */
	public function test_post_in_free_returns_403_registers_pro(): void {
		// Arrange.
		$before = ( new Register_Store() )->list( array() );

		// Act.
		$responses = array( $this->post_register( array( 'name' => 'Second' ) ), $this->post_register( array( 'name' => '' ) ) );

		// Assert.
		foreach ( $responses as $response ) {
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'wcpos_registers_pro', $response->get_data()['code'] );
			$this->assertSame( 'More registers need WCPOS Pro.', $response->get_data()['message'] );
		}
		$this->assertSame( $before, ( new Register_Store() )->list( array() ) );
	}

	/** Dispatch retirement with the shared WCPOS request headers. */
	private function delete_register( string $id ) {
		$request = $this->wp_rest_get_request( '/wcpos/v2/registers/' . $id );
		$request->set_method( 'DELETE' );
		return $this->server->dispatch( $request );
	}

	/** Free managers cannot retire the one register. */
	public function test_delete_in_free_returns_403_registers_pro(): void {
		$row = ( new Register_Store() )->list( array() )[0];
		$response = $this->delete_register( $row['id'] );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpos_registers_pro', $response->get_data()['code'] );
		$this->assertNotNull( ( new Register_Store() )->get( $row['id'] ) );
	}

	/** Access alone does not grant retirement. */
	public function test_delete_access_only_user_returns_403(): void {
		$row = ( new Register_Store() )->list( array() )[0];
		$cashier = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $cashier );
		$response = $this->delete_register( $row['id'] );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** Unknown registers stay hidden rather than offering an upgrade. */
	public function test_delete_unknown_register_returns_404(): void {
		$response = $this->delete_register( wp_generate_uuid4() );
		$this->assertSame( 404, $response->get_status() );
	}

	/** Free lists its one register; a status query is no longer a filter. */
	public function test_get_items_in_free_lists_the_one_register(): void {
		// Arrange.
		$request = $this->wp_rest_get_request( '/wcpos/v2/registers' );
		$request->set_query_params( array( 'status' => 'retired' ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( get_option( Option_Register_Repository::OPTION )['register']['id'], $response->get_data()[0]['id'] );
		$this->assertNull( $response->get_data()[0]['store_id'] );
	}

	/** Registers without activity explicitly return nullable live-state fields. */
	public function test_list_without_session_or_closure_returns_nulls(): void {
		// Arrange.
		$this->create_register();

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'session', $response->get_data()[0] );
		$this->assertArrayHasKey( 'last_closure', $response->get_data()[0] );
		$this->assertSame( null, $response->get_data()[0]['session'] );
		$this->assertSame( null, $response->get_data()[0]['last_closure'] );
	}

	/** Open and counting sessions expose their opener and UTC opening, not session internals. */
	public function test_list_current_session_returns_status_opening_and_actor(): void {
		// Arrange.
		$register = $this->create_register()['id'];
		wp_update_user( array( 'ID' => $this->user, 'display_name' => 'Amy Okafor' ) );
		$session = $this->closure_session( $register, 'open' );
		$sessions = new Register_Session_Store();
		foreach ( array( 'open', 'counting' ) as $status ) {
			if ( 'counting' === $status ) {
				$session = $sessions->transition( $session, array( 'status' => 'counting' ) );
			}

			// Act.
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers' ) );

			// Assert.
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame(
				array(
					'id' => $session['id'],
					'status' => $status,
					'opened_at_gmt' => '2026-09-11T08:00:00Z',
					'opened_by' => array( 'id' => $this->user, 'name' => 'Amy Okafor' ),
				),
				$response->get_data()[0]['session']
			);
			$this->assertSame( null, $response->get_data()[0]['last_closure'] );
			$this->assertSame( $response->get_data()[0]['session'], ( new Register_Store() )->get( $register )['session'] );
			$item = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/' . $register ) );
			$this->assertSame( 200, $item->get_status() );
			$this->assertSame( $response->get_data()[0]['session'], $item->get_data()['session'] );
			$this->assertSame( $response->get_data()[0]['last_closure'], $item->get_data()['last_closure'] );
		}

		// A removed opener still has an identity, but no display name.
		global $wpdb;
		$wpdb->update( $sessions->table_name(), array( 'opened_by' => 0 ), array( 'id' => $session['id'] ) );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers' ) );
		$this->assertSame( array( 'id' => 0, 'name' => '' ), $response->get_data()[0]['session']['opened_by'] );
	}

	/** A numbered close removes the live session and retains the latest closure beside counters. */
	public function test_list_closed_session_returns_latest_numbered_closure(): void {
		// Arrange.
		$register = $this->create_register()['id'];
		$this->closure_registers[] = $register; // Closure writes commit; the shared fixture removes them.
		foreach ( array( 1, 2 ) as $number ) {
			$session = $this->closure_session( $register );
			$closure = ( new Closure_Store() )->create( $this->closure_fields( $session, $number ) );
			$this->assertIsArray( $closure );

			// Act.
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers' ) );

			// Assert.
			$this->assertSame( 200, $response->get_status() );
			$row = $response->get_data()[0];
			$this->assertSame( null, $row['session'] );
			$this->assertSame( array( 'number' => $number, 'closed_at_gmt' => '2026-09-11T12:00:00Z' ), $row['last_closure'] );
			$this->assertSame( $row['counters']['last_closure_number'], $row['last_closure']['number'] );
			$this->assertSame( $row['last_closure'], ( new Register_Store() )->get( $register )['last_closure'] );
			$item = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/' . $register ) );
			$this->assertSame( 200, $item->get_status() );
			$this->assertSame( $row['session'], $item->get_data()['session'] );
			$this->assertSame( $row['last_closure'], $item->get_data()['last_closure'] );
		}
	}

	/** Listing more registers batches session, closure and cold opener reads. */
	public function test_list_multiple_open_sessions_uses_constant_query_count(): void {
		// Arrange.
		$this->use_multi_register_repository();
		$users = array();
		$queries = array();
		foreach ( array( 1, 3 ) as $count ) {
			while ( count( $users ) < $count ) {
				$register = $this->create_register()['id'];
				$user = self::factory()->user->create();
				$users[] = $user;
				wp_set_current_user( $user );
				$this->closure_session( $register, 'open' );
				wp_set_current_user( $this->user );
			}
			// Warm route setup, but leave each distinct opener uncached for the measurement.
			$this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers' ) );
			foreach ( $users as $user ) {
				clean_user_cache( $user );
				wp_cache_delete( $user, 'user_meta' );
			}
			$request = $this->wp_rest_get_request( '/wcpos/v2/registers' );

			// Act.
			$before = get_num_queries();
			$response = $this->server->dispatch( $request );
			$queries[ $count ] = get_num_queries() - $before;

			// Assert.
			$this->assertSame( 200, $response->get_status() );
			$this->assertCount( $count, $response->get_data() );
			$this->assertCount( $count, array_filter( array_column( $response->get_data(), 'session' ) ) );
		}
		$this->assertSame( $queries[1], $queries[3], 'One and three registers must use the same number of queries.' );
	}

	/** Batch lookup takes one query, keeps the newest non-closed row and excludes unrelated registers. */
	public function test_open_for_registers_batches_and_keys_current_sessions(): void {
		// Arrange: fabricate IDs rather than requiring Pro to create two registers.
		global $wpdb;
		$store = new Register_Session_Store();
		$store->ensure_installed();
		$registers = array( wp_generate_uuid4(), wp_generate_uuid4(), wp_generate_uuid4() );
		$ids = array();
		foreach ( array(
			array( $registers[0], 'open', '2026-09-11 08:00:00' ),
			array( $registers[0], 'counting', '2026-09-11 09:00:00' ),
			array( $registers[0], 'closed', '2026-09-11 10:00:00' ),
			array( $registers[1], 'open', '2026-09-11 08:00:00' ),
			array( $registers[2], 'closed', '2026-09-11 08:00:00' ),
			array( wp_generate_uuid4(), 'open', '2026-09-11 08:00:00' ),
		) as list( $register, $status, $opened ) ) {
			$ids[] = $id = wp_generate_uuid4();
			$this->assertSame( 1, $wpdb->insert( $store->table_name(), array(
				'id' => $id,
				'register_id' => $register,
				'store_id' => 456,
				'status' => $status,
				'opened_at_gmt' => $opened,
				'opened_by' => $this->user,
				'counted_float' => '100',
				'counted' => '{"cash":"100"}',
				'created_at_gmt' => $opened,
			) ) );
		}

		// Act.
		$before = get_num_queries();
		$rows = $store->open_for_registers( $registers );
		$queries = get_num_queries() - $before;

		// Assert.
		$this->assertSame( 1, $queries );
		$this->assertCount( 2, $rows );
		$this->assertSame( $ids[1], $rows[ $registers[0] ]['id'] );
		$this->assertSame( 'counting', $rows[ $registers[0] ]['status'] );
		$this->assertSame( $ids[3], $rows[ $registers[1] ]['id'] );
		$this->assertSame( 'open', $rows[ $registers[1] ]['status'] );
		$this->assertSame( 456, $rows[ $registers[0] ]['store_id'] );
		$this->assertSame( $this->user, $rows[ $registers[0] ]['opened_by'] );
		$this->assertSame( null, $rows[ $registers[0] ]['closed_by'] );
		$this->assertSame( array( 'cash' => '100' ), $rows[ $registers[0] ]['counted'] );
		$before = get_num_queries();
		$this->assertSame( array(), $store->open_for_registers( array() ) );
		$this->assertSame( $before, get_num_queries() );
	}

	/** Clients can discover the nullable, read-only live-state contract. */
	public function test_register_schema_describes_session_and_last_closure(): void {
		// Arrange.
		$request = $this->wp_rest_get_request( '/wcpos/v2/registers' );
		$request->set_method( 'OPTIONS' );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$properties = $response->get_data()['schema']['properties'];
		foreach ( array( 'session', 'last_closure' ) as $key ) {
			$this->assertSame( true, $properties[ $key ]['readonly'] );
			$this->assertSame( array( 'object', 'null' ), $properties[ $key ]['type'] );
		}
		$session = $properties['session']['properties'];
		$this->assertSame( 'uuid', $session['id']['format'] );
		$this->assertSame( array( 'open', 'counting' ), $session['status']['enum'] );
		$this->assertSame( 'date-time', $session['opened_at_gmt']['format'] );
		$this->assertSame( 'integer', $session['opened_by']['properties']['id']['type'] );
		$this->assertSame( 'string', $session['opened_by']['properties']['name']['type'] );
		$this->assertSame( 'integer', $properties['last_closure']['properties']['number']['type'] );
		$this->assertSame( 'date-time', $properties['last_closure']['properties']['closed_at_gmt']['format'] );
	}

	/** With a repository holding more (Pro), managers create server identities with read-only counters. */
	public function test_post_manager_creates_server_uuid_and_get_includes_counters(): void {
		$this->use_multi_register_repository();
		$first = $this->post_register(
			array(
				'name' => 'Front',
				'default_float' => '5.00',
			)
		);
		$this->assertSame( 201, $first->get_status() );
		$row = $first->get_data();
		$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Pos_Uuid::is_uuid( $row['id'] ) );
		$this->assertSame( '5.0000', $row['default_float'] );
		$this->assertSame(
			array(
				'last_closure_number' => 0,
				'perpetual_sales_total' => '0',
				'perpetual_refunds_total' => '0',
			),
			$row['counters']
		);
		$get = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/' . $row['id'] ) );
		$this->assertSame( 200, $get->get_status() );
		$this->assertSame( $row['counters'], $get->get_data()['counters'] );
	}

	/** POS access alone does not grant register creation. */
	public function test_post_access_only_user_returns_403(): void {
		$cashier = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $cashier );
		$this->assertSame( 403, $this->post_register( array( 'name' => 'Front' ) )->get_status() );
	}

	/** Create refuses client-owned identity and malformed admin fields. */
	public function test_post_rejects_till_fields_and_invalid_create_values(): void {
		$this->use_multi_register_repository();
		foreach ( array(
			'id' => wp_generate_uuid4(),
			'platform' => 'ios',
			'app_version' => 'test',
			'default_float' => '-1',
			'name' => '',
		) as $key => $value ) {
			$response = $this->post_register( array_merge( array( 'name' => 'Front' ), array( $key => $value ) ) );
			$this->assertSame( 400, $response->get_status(), $key );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		}
	}

	/** Default floats must fit the backing DECIMAL(19,4) column after normalization. */
	public function test_default_float_rejects_normalized_overflow_on_create_and_update(): void {
		$this->use_multi_register_repository();
		$response = $this->post_register(
			array(
				'name' => 'Front',
				'default_float' => '999999999999999.99999',
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );

		$id = $this->post_register( array( 'name' => 'Front' ) )->get_data()['id'];
		$request = $this->wp_rest_patch_request( '/wcpos/v2/registers/' . $id );
		$request->set_method( 'PATCH' );
		$request->set_body_params( array( 'default_float' => '1000000000000000' ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/** A default float keeps its four decimals above 2^53, where floatval() would drop them. */
	public function test_default_float_keeps_its_fraction_above_float_precision_on_create_and_update(): void {
		// Arrange / Act.
		$this->use_multi_register_repository();
		$created = $this->post_register(
			array(
				'name' => 'Front',
				'default_float' => '100000000000000.0001',
			)
		);
		// Assert.
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( '100000000000000.0001', $created->get_data()['default_float'] );
		$this->assertSame( '100000000000000.0001', ( new Register_Store() )->get( $created->get_data()['id'] )['default_float'] );

		// Arrange / Act: the same value through PATCH, and rounding stays half-up at the fourth decimal.
		foreach ( array(
			'100000000000000.0001' => '100000000000000.0001',
			'1.23456' => '1.2346',
			'0000000000000001' => '1.0000',
		) as $sent => $stored ) {
			$request = $this->wp_rest_patch_request( '/wcpos/v2/registers/' . $created->get_data()['id'] );
			$request->set_method( 'PATCH' );
			$request->set_body_params( array( 'default_float' => (string) $sent ) );
			$updated = $this->server->dispatch( $request );
			// Assert.
			$this->assertSame( 200, $updated->get_status(), $sent );
			$this->assertSame( $stored, $updated->get_data()['default_float'], $sent );
		}
	}

	/** The Pro filter sees, but cannot replace, the final server identity. */
	public function test_create_filter_cannot_replace_server_id(): void {
		$this->use_multi_register_repository();
		$seen = null;
		$filter = function ( $fields, $request ) use ( &$seen ) {
			$seen = $fields['id'];
			$this->assertFalse( ( new Register_Store() )->exists( $seen ) );
			$this->assertSame( 'Front', $request['name'] );
			$fields['id'] = wp_generate_uuid4();
			$fields['store_id'] = 123;
			return $fields;
		};
		add_filter( 'woocommerce_pos_register_upsert_fields', $filter, 10, 2 );
		try {
			$response = $this->post_register( array( 'name' => 'Front' ) );
		} finally {
			remove_filter( 'woocommerce_pos_register_upsert_fields', $filter );
		}
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $seen, $response->get_data()['id'] );
		$this->assertSame( 123, $response->get_data()['store_id'] );
	}

	/** Patch requires a manager and changes only the name and float of Free's register. */
	public function test_patch_requires_manager_and_ignores_status_and_store(): void {
		$id = $this->create_register()['id'];
		$request = $this->wp_rest_patch_request( '/wcpos/v2/registers/' . $id );
		$request->set_method( 'PATCH' );
		$request->set_body_params(
			array(
				'name' => 'Back',
				'status' => 'retired',
				'default_float' => '12.50',
				'store_id' => 99,
			)
		);
		$cashier = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $cashier );
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
		wp_set_current_user( $this->user );
		$updated = $this->server->dispatch( $request );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'Back', $updated->get_data()['name'] );
		$this->assertArrayNotHasKey( 'status', $updated->get_data() );
		$this->assertSame( '12.5000', $updated->get_data()['default_float'] );
		$this->assertNull( $updated->get_data()['store_id'] );
		$list = $this->wp_rest_get_request( '/wcpos/v2/registers' );
		$this->assertSame( array( $id ), array_column( $this->server->dispatch( $list )->get_data(), 'id' ) );
	}

	/** A competing register lock prevents PATCH from changing the row. */
	public function test_patch_register_locked_returns_500_without_updating(): void {
		// Arrange.
		$store = new Register_Store();
		$row = $this->create_register( array( 'name' => 'Front' ) );
		$request = $this->wp_rest_patch_request( '/wcpos/v2/registers/' . $row['id'] );
		$request->set_method( 'PATCH' );
		$request->set_body_params( array( 'name' => 'Back' ) );
		$contender = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name = 'wcpos_register_' . $row['id'];
		try {
			$this->assertSame( '1', (string) $contender->get_var( $contender->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ) );
			// Act.
			$response = $this->server->dispatch( $request );
			// Assert.
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( 'wcpos_register_write_failed', $response->get_data()['code'] );
			$this->assertSame( $row, $store->get( $row['id'] ) );
		} finally {
			$contender->get_var( $contender->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
			$contender->close();
		}
	}

	/** A register deleted while PATCH waits answers exactly as unknown. */
	public function test_patch_register_deleted_at_lock_returns_not_found_without_update(): void {
		// Arrange.
		$repository = $this->use_multi_register_repository();
		$store = new Register_Store();
		$row = $this->create_register( array( 'name' => 'Front' ) );
		$request = $this->wp_rest_patch_request( '/wcpos/v2/registers/' . $row['id'] );
		$request->set_method( 'PATCH' );
		$request->set_body_params( array( 'name' => 'Back' ) );
		$race = static function ( $sql ) use ( $repository, $row ) {
			if ( false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $row['id'] ) ) {
				$repository->forget( $row['id'] );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			// Act.
			$response = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'query', $race );
		}
		// Assert.
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_register_not_found', $response->get_data()['code'] );
		$this->assertNull( $store->get( $row['id'] ) );
		$this->assertSame( array(), $repository->rows );
	}

	/** PATCH locks before the Pro guard and the register write. */
	public function test_patch_update_filter_and_write_run_after_register_lock(): void {
		// Arrange.
		global $wpdb;
		$row = $this->create_register( array( 'name' => 'Front' ) );
		$request = $this->wp_rest_patch_request( '/wcpos/v2/registers/' . $row['id'] );
		$request->set_method( 'PATCH' );
		$request->set_body_params( array( 'name' => 'Back' ) );
		$queries = array();
		$lock_sql = "GET_LOCK( 'wcpos_register_" . $row['id'];
		$filter_saw_lock = false;
		$trace = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		$filter = static function ( $fields ) use ( &$queries, $lock_sql, &$filter_saw_lock ) {
			$filter_saw_lock = false !== strpos( implode( "\n", $queries ), $lock_sql );
			return $fields;
		};
		add_filter( 'query', $trace );
		add_filter( 'woocommerce_pos_register_update_fields', $filter );
		try {
			// Act.
			$response = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'woocommerce_pos_register_update_fields', $filter );
			remove_filter( 'query', $trace );
		}
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Back', $response->get_data()['name'] );
		$sql = implode( "\n", $queries );
		$lock = strpos( $sql, $lock_sql );
		$update = strpos( $sql, "UPDATE `{$wpdb->options}`" );
		$this->assertNotFalse( $lock );
		$this->assertNotFalse( $update );
		$this->assertGreaterThan( $lock, $update );
		$this->assertTrue( $filter_saw_lock );
	}

	/** Unknown register returns 404. */
	public function test_unknown_register_returns_404(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/' . wp_generate_uuid4() ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_register_not_found', $response->get_data()['code'] );
	}

	/** Health pos user receives diagnostics. */
	public function test_health_pos_user_receives_diagnostics(): void {
		$cashier = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $cashier );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/health' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'registers', $response->get_data() );
		$this->assertArrayHasKey( 'unregistered', $response->get_data() );
	}

	/** Health without pos access returns forbidden. */
	public function test_health_without_pos_access_returns_forbidden(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/health' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/** Health literal is not an item id and is protocol exempt. */
	public function test_health_literal_is_not_an_item_id_and_is_protocol_exempt(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v2/registers/health', $routes );
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/wcpos/v2/registers/' ) && false !== strpos( $route, '(?P<id>' ) ) {
				$this->assertSame( 0, preg_match( '@^' . $route . '$@', '/wcpos/v2/registers/health' ) );
			}
		}
		// The registers entry covers every route below it; no separate health entry.
		$classifier = new \WCPOS\WooCommercePOS\API\Route_Classifier( array( 'wcpos/v2' ) );
		$classifier->merge( ( new \WCPOS\WooCommercePOS\API\V2\Registers_Controller() )->wcpos_route_classifications() );
		$this->assertTrue( $classifier->is_protocol_exempt( '/wcpos/v2/registers/health' ) );
	}

	/** Health applies the store scope to the registers it reports. */
	public function test_health_applies_store_scope(): void {
		$this->use_multi_register_repository();
		$id = $this->create_register(
			array(
				'name' => 'In scope',
				'store_id' => 123,
			)
		)['id'];
		$this->create_register(
			array(
				'name' => 'Other store',
				'store_id' => 456,
			)
		);
		$scope = function ( $args, $request ) {
			$this->assertSame( array(), $args );
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

	/** A retired register's orders are provenance the site knows, not unregistered; an unknown id's are. */
	public function test_health_treats_retired_register_ids_as_known(): void {
		// Arrange.
		$retired = wp_generate_uuid4();
		$unknown = wp_generate_uuid4();
		( new Register_Store() )->list( array() );
		$option = get_option( Option_Register_Repository::OPTION );
		$option['retired'][ $retired ] = array(
			'id' => $retired,
			'name' => 'Migrated till',
			'store_id' => 5,
			'default_float' => null,
			'created_at_gmt' => '2026-09-01 08:00:00',
		);
		update_option( Option_Register_Repository::OPTION, $option, true );
		foreach ( array( $retired, $unknown ) as $register ) {
			$order = new \WC_Order();
			$order->update_meta_data( '_wcpos_register', $register );
			$order->save();
		}

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers/health' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$unregistered = array_column( $response->get_data()['unregistered'], 'register_id' );
		$this->assertNotContains( $retired, $unregistered );
		$this->assertContains( $unknown, $unregistered );
	}

	/** Health options describes diagnostics not register item. */
	public function test_health_options_describes_diagnostics_not_register_item(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/registers/health' );
		$request->set_method( 'OPTIONS' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$properties = $response->get_data()['schema']['properties'];
		$this->assertArrayHasKey( 'window_days', $properties );
		$this->assertArrayHasKey( 'registers', $properties );
		$this->assertArrayHasKey( 'truncated', $properties );
		$register = $properties['registers']['items']['properties'];
		$this->assertSame( array( 'type' => 'integer' ), $register['duplicates']['items']['properties']['orders'] );
		$this->assertSame( array( 'type' => 'string' ), $register['skew']['items']['properties']['till'] );
		$this->assertSame( 'object', $register['skew_counts']['type'] );
		$this->assertSame(
			array(
				'ahead' => array( 'type' => 'integer' ),
				'behind' => array( 'type' => 'integer' ),
			),
			$register['skew_counts']['properties']
		);
		$this->assertArrayNotHasKey( 'name', $properties );
		$this->assertArrayNotHasKey( 'status', $properties );
	}

	/** Health scope keeps global known ids and filters order stores. */
	public function test_health_scope_keeps_global_known_ids_and_filters_order_stores(): void {
		$unknown = wp_generate_uuid4();
		$this->use_multi_register_repository();
		$id = $this->create_register(
			array(
				'name' => 'Front',
				'store_id' => 1,
			)
		)['id'];
		$other = $this->create_register(
			array(
				'name' => 'Moved till',
				'store_id' => 2,
			)
		)['id'];
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
	/** Neither GET nor PATCH reveals or edits an unreachable register. */
	public function test_register_item_outside_store_reach_reads_as_absent(): void {
		// Arrange.
		$this->use_multi_register_repository();
		$store = new Register_Store();
		$row = $this->create_register(
			array(
				'name' => 'Original',
				'store_id' => 456,
			)
		);
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			foreach ( array( 'wp_rest_get_request', 'wp_rest_patch_request' ) as $method ) {
				// Act.
				$absent_request = $this->$method( '/wcpos/v2/registers/' . wp_generate_uuid4() );
				$request = $this->$method( '/wcpos/v2/registers/' . $row['id'] );
				if ( 'wp_rest_patch_request' === $method ) {
					$absent_request->set_method( 'PATCH' );
					$request->set_method( 'PATCH' );
				}
				$absent_request->set_param( 'name', 'Renamed' );
				$request->set_param( 'name', 'Renamed' );
				$absent = $this->server->dispatch( $absent_request );
				$response = $this->server->dispatch( $request );
				// Assert.
				$this->assertSame( $absent->get_status(), $response->get_status() );
				$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
			}
			$this->assertSame( 'Original', $store->get( $row['id'] )['name'] );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}

	/** The register SQL list accepts the reach's array of allowed stores. */
	public function test_register_list_is_narrowed_to_store_reach(): void {
		// Arrange.
		$this->use_multi_register_repository();
		foreach ( array( 456, 789 ) as $store_id ) {
			$row = $this->create_register(
				array(
					'name' => 'Store ' . $store_id,
					'store_id' => $store_id,
				)
			);
		}
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			// Act.
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/registers' ) );
			// Assert.
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array( $row['id'] ), array_column( $response->get_data(), 'id' ) );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}
	/** Unassigned register reads, edits and health require the explicit key. */
	public function test_unassigned_register_is_reachable_only_with_the_unassigned_key(): void {
		// Arrange.
		$this->use_multi_register_repository();
		$row = $this->create_register( array( 'name' => 'Unassigned' ) );
		foreach ( array( true, false ) as $unassigned ) {
			$filter = static function () use ( $unassigned ) {
				return array( 'store_id' => array( 789 ) ) + ( $unassigned ? array( 'unassigned' => true ) : array() );
			};
			add_filter( Store_Reach::FILTER, $filter );
			try {
				foreach ( array( 'GET', 'PATCH' ) as $method ) {
					// Act.
					$request = $this->wp_rest_get_request( '/wcpos/v2/registers/' . $row['id'] );
					$request->set_method( $method );
					$request->set_param( 'name', 'Renamed' );
					$response = $this->server->dispatch( $request );
					// Assert.
					$this->assertSame( $unassigned ? 200 : 404, $response->get_status() );
					if ( ! $unassigned ) {
						$request->set_route( '/wcpos/v2/registers/' . wp_generate_uuid4() );
						$absent = $this->server->dispatch( $request );
						$this->assertSame( $absent->get_status(), $response->get_status() );
						$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
					}
				}
				foreach ( array( '', '/health' ) as $suffix ) {
					$request = $this->wp_rest_get_request( '/wcpos/v2/registers' . $suffix );
					$response = $this->server->dispatch( $request );
					$this->assertSame( 200, $response->get_status() );
					$rows = $suffix ? $response->get_data()['registers'] : $response->get_data();
					$ids  = array_column( $rows, 'id' );
					if ( $unassigned ) {
						$this->assertContains( $row['id'], $ids );
					} else {
						$this->assertSame( array(), $ids );
					}
				}
			} finally {
				remove_filter( Store_Reach::FILTER, $filter );
			}
		}
	}
}
