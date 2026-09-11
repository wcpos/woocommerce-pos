<?php
/**
 * Sessions REST tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Session and movement REST contracts. */
class Test_Sessions_Controller extends WCPOS_REST_Unit_Test_Case {
	use \WCPOS\WooCommercePOS\Tests\Services\Session_Expected_Tests;

	/** Registers created by this test, including rows committed by void transactions.
	 *
	 * @var array
	 */
	private $register_ids = array();

	/** Remove this test's committed bookkeeping after the framework rollback. */
	public function tearDown(): void {
		parent::tearDown();
		global $wpdb;
		foreach ( $this->register_ids as $id ) {
			foreach ( ( new Register_Session_Store() )->list( array( 'register_id' => $id ) ) as $session ) {
				$wpdb->delete( ( new Cash_Movement_Store() )->table_name(), array( 'session_id' => $session['id'] ) );
			}
			$wpdb->delete( ( new Register_Session_Store() )->table_name(), array( 'register_id' => $id ) );
			$wpdb->delete( ( new Register_Store() )->table_name(), array( 'id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
	}

	/** Install owners and grant cash management. */
	public function setUp(): void {
		parent::setUp();
		( new Register_Session_Store() )->install();
		( new Cash_Movement_Store() )->install();
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
	}

	/** Dispatch a v2 write.
	 *
	 * @param string $route Resource path.
	 * @param array  $fields Request fields.
	 */
	private function post( string $route, array $fields ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/' . $route );
		$request->set_body_params( $fields );
		return $this->server->dispatch( $request );
	}

	/** A valid client opening. */
	private function fields(): array {
		$register_id = ( new Register_Store() )->create( array( 'name' => 'Front' ) )['id'];
		$this->register_ids[] = $register_id;
		return array(
			'id' => wp_generate_uuid4(),
			'register_id' => $register_id,
			'opened_at' => '2026-09-11T10:00:00+02:00',
			'expected_float' => '95',
			'counted_float' => '100',
		);
	}

	/** Create preserves identity, computes variance and refuses unknown or busy registers. */
	public function test_create_replay_conflict_and_unknown_register(): void {
		$fields = $this->fields();
		$first = $this->post( 'sessions', $fields );
		$this->assertSame( 201, $first->get_status() );
		$row = $first->get_data();
		$this->assertSame( '5.0000', $row['opening_variance'] );
		$this->assertSame( '2026-09-11 08:00:00', $row['opened_at_gmt'] );
		$this->assertSame( get_current_user_id(), $row['opened_by'] );
		$replay = $this->post(
			'sessions',
			array(
				'id' => $fields['id'],
				'counted_float' => 'bad',
			)
		);
		$this->assertSame( 200, $replay->get_status() );
		$this->assertSame( $row, $replay->get_data() );
		$fields['id'] = wp_generate_uuid4();
		$conflict = $this->post( 'sessions', $fields );
		$this->assertSame( 409, $conflict->get_status() );
		$this->assertSame( 'wcpos_session_already_open', $conflict->get_data()['code'] );
		$this->assertSame( $row['id'], $conflict->get_data()['data']['session_id'] );
		$fields['register_id'] = wp_generate_uuid4();
		$this->assertSame( 400, $this->post( 'sessions', $fields )->get_status() );
	}

	/** Reads require POS access and writes require cash management. */
	public function test_access_only_can_read_but_cannot_write(): void {
		$fields = $this->fields();
		$this->post( 'sessions', $fields );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user );
		foreach ( array( 'sessions', 'sessions/' . $fields['id'] . '/status', 'movements' ) as $route ) {
			$this->assertSame( 403, $this->post( $route, $fields )->get_status() );
		}
		foreach ( array( 'sessions', 'sessions/' . $fields['id'], 'sessions/' . $fields['id'] . '/movements' ) as $route ) {
			$this->assertSame( 200, $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/' . $route ) )->get_status() );
		}
	}

	/** Malformed opening fields are refused and an unknown float has no variance. */
	public function test_validation_and_null_expected_float(): void {
		$fields = $this->fields();
		foreach ( array(
			'id' => 'bad',
			'register_id' => 'bad',
			'opened_at' => '2026-02-30T10:00:00Z',
			'counted_float' => '-1',
			'expected_float' => '1e2',
			'store_id' => array(),
		) as $key => $value ) {
			$this->assertSame( 400, $this->post( 'sessions', array_merge( $fields, array( $key => $value ) ) )->get_status(), $key );
		}
		$fields['expected_float'] = null;
		$this->assertNull( $this->post( 'sessions', $fields )->get_data()['opening_variance'] );
	}

	/** Only specified transitions succeed; close requires counted cash. */
	public function test_transitions_close_counts_and_noop(): void {
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$route = 'sessions/' . $id . '/status';
		$fields = array(
			'status' => 'closed',
			'at' => '2026-09-11T11:00:00Z',
		);
		$this->assertSame( 409, $this->post( $route, $fields )->get_status() );
		foreach ( array( 'counting', 'open', 'counting' ) as $status ) {
			$fields['status'] = $status;
			$result = $this->post( $route, $fields );
			$this->assertSame( 200, $result->get_status() );
			$this->assertSame( 'open' === $status ? null : '2026-09-11 11:00:00', $result->get_data()['counting_started_at_gmt'] );
			$this->assertSame( $result->get_data(), $this->post( $route, array( 'status' => $status ) )->get_data() );
		}
		$fields['status'] = 'closed';
		$this->assertSame( 400, $this->post( $route, $fields )->get_status() );
		$fields['counted'] = array(
			'cash' => '100',
			'card' => '-1',
		);
		$this->assertSame( 400, $this->post( $route, $fields )->get_status() );
		$fields['counted'] = array( 'cash' => '100' );
		$result = $this->post( $route, $fields );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( get_current_user_id(), $result->get_data()['closed_by'] );
		$this->assertSame( '2026-09-11 11:00:00', $result->get_data()['closed_at_gmt'] );
		$this->assertSame( array( 'cash' => '100.0000' ), $result->get_data()['counted'] );
		$this->assertSame( $result->get_data(), $this->post( $route, array( 'status' => 'closed' ) )->get_data() );
		foreach ( array( 'open', 'counting' ) as $status ) {
			$fields['status'] = $status;
			$this->assertSame( 409, $this->post( $route, $fields )->get_status() );
		}
	}

	/** Approval requires a different user with closure management. */
	public function test_approver_requires_another_closure_manager(): void {
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$route = 'sessions/' . $id . '/status';
		$fields = array(
			'status' => 'counting',
			'at' => '2026-09-11T11:00:00Z',
		);
		$this->post( $route, $fields );
		$fields['status'] = 'closed';
		$fields['counted'] = array( 'cash' => '100' );
		$cashier = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$fields['approver_token'] = Auth::instance()->generate_access_token( $cashier );
		$this->assertSame( 403, $this->post( $route, $fields )->get_status() );
		$fields['approver_token'] = Auth::instance()->generate_access_token( wp_get_current_user() );
		$this->assertSame( 403, $this->post( $route, $fields )->get_status() );
		$cashier->add_cap( 'manage_woocommerce_pos_closures' );
		$fields['approver_token'] = Auth::instance()->generate_access_token( $cashier );
		$result = $this->post( $route, $fields );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( $cashier->ID, $result->get_data()['approved_by'] );
	}

	/** Movements enforce amounts, append once, and refuse invalid void targets. */
	public function test_movements_validation_voids_and_replays(): void {
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $id,
			'type' => 'paid_in',
			'amount' => '20',
			'reason' => 'Change',
			'created_at' => '2026-09-11T10:00:00Z',
		);
		foreach ( array(
			'paid_in' => '20',
			'paid_out' => '5',
			'no_sale' => '0',
		) as $type => $amount ) {
			$fields = array_merge(
				$fields,
				array(
					'id' => wp_generate_uuid4(),
					'type' => $type,
					'amount' => $amount,
				)
			);
			$result = $this->post( 'movements', $fields );
			$this->assertSame( 201, $result->get_status() );
			$this->assertSame( get_current_user_id(), $result->get_data()['actor'] );
			$replay = $this->post( 'movements', array( 'id' => $fields['id'] ) );
			$this->assertSame( 200, $replay->get_status() );
			$this->assertSame( $result->get_data(), $replay->get_data() );
			if ( 'paid_in' === $type ) {
				$unvoided = $fields['id'];
			}
			if ( 'paid_out' === $type ) {
				$target = $fields['id'];
			}
		}
		$fields['id'] = wp_generate_uuid4();
		foreach ( array(
			'paid_in' => '0',
			'no_sale' => '3',
		) as $type => $amount ) {
			$this->assertSame(
				400,
				$this->post(
					'movements',
					array_merge(
						$fields,
						array(
							'type' => $type,
							'amount' => $amount,
						)
					)
				)->get_status()
			);
		}
		foreach ( array( array( 'amount' => '0.00001' ), array( 'reason' => str_repeat( 'x', 501 ) ), array( 'reason' => '' ), array( 'reason' => array() ), array( 'created_at' => 'bad' ) ) as $invalid ) {
			$this->assertSame( 400, $this->post( 'movements', array_merge( $fields, $invalid ) )->get_status() );
		}
		$fields = array_merge(
			$fields,
			array(
				'type' => 'void',
				'amount' => '0',
				'voids' => $target,
				'reason' => '',
			)
		);
		$this->assertSame( 201, $this->post( 'movements', $fields )->get_status() );
		$this->assertSame( $fields['id'], ( new Cash_Movement_Store() )->get( $target )['voided_by'] );
		$this->assertSame( 200, $this->post( 'movements', $fields )->get_status() );
		$void = $fields['id'];
		$fields['id'] = wp_generate_uuid4();
		foreach ( array( $target, $void, wp_generate_uuid4() ) as $target_id ) {
			$fields['voids'] = $target_id;
			$this->assertSame( 409, $this->post( 'movements', $fields )->get_status() );
		}
		$this->assertCount( 4, ( new Cash_Movement_Store() )->list( $id ) );
		$other = $this->post( 'sessions', $this->fields() )->get_data();
		$fields['voids'] = $unvoided;
		$fields['session_id'] = $other['id'];
		$this->assertSame( 409, $this->post( 'movements', $fields )->get_status() );
		$fields['session_id'] = $id;
		$get = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $id . '/movements' ) );
		$this->assertSame( ( new Cash_Movement_Store() )->list( $id ), $get->get_data() );
		foreach ( array( 'counting', 'closed' ) as $status ) {
			$this->post(
				'sessions/' . $id . '/status',
				array(
					'status' => $status,
					'at' => '2026-09-11T12:00:00Z',
					'counted' => array( 'cash' => '100' ),
				)
			);
			$this->assertSame( 409, $this->post( 'movements', $fields )->get_status() );
		}
	}
	/** Lists are newest first, paginated, validated and extension-scoped. */
	public function test_list_pagination_and_extension_filters(): void {
		$scope = static function ( $fields ) {
			$fields['store_id'] = 765;
			return $fields;
		};
		add_filter( 'woocommerce_pos_session_create_fields', $scope );
		$a = $this->post( 'sessions', $this->fields() )->get_data();
		$b = $this->post( 'sessions', array_merge( $this->fields(), array( 'opened_at' => '2026-09-12T10:00:00Z' ) ) )->get_data();
		remove_filter( 'woocommerce_pos_session_create_fields', $scope );
		$this->assertSame( 765, $a['store_id'] );
		$list_scope = static function ( $args ) {
			$args['store_id'] = 765;
			return $args;
		};
		add_filter( 'woocommerce_pos_sessions_list_args', $list_scope );
		try {
			foreach ( array(
				1 => $b['id'],
				2 => $a['id'],
			) as $page => $id ) {
				$request = $this->wp_rest_get_request( '/wcpos/v2/sessions' );
				$request->set_query_params(
					array(
						'page' => $page,
						'per_page' => 1,
					)
				);
				$this->assertSame( array( $id ), array_column( $this->server->dispatch( $request )->get_data(), 'id' ) );
			}
			$request->set_query_params(
				array(
					'register_id' => $a['register_id'],
					'status' => 'open',
				)
			);
			$this->assertSame( array( $a['id'] ), array_column( $this->server->dispatch( $request )->get_data(), 'id' ) );
			foreach ( array( array( 'status' => 'invalid' ), array( 'per_page' => 101 ), array( 'page' => 0 ), array( 'register_id' => 'bad' ) ) as $params ) {
				$request->set_query_params( $params );
				$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
			}
		} finally {
			remove_filter( 'woocommerce_pos_sessions_list_args', $list_scope );
		}
	}

	/** Refusal at the conditional stamp is a conflict, not a persisted orphan or 500. */
	public function test_void_stamp_zero_rows_rolls_back_and_returns_409(): void {
		$session = $this->post( 'sessions', $this->fields() )->get_data();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_out',
			'amount' => '5',
			'reason' => 'Change',
			'created_at' => '2026-09-11T10:00:00Z',
		);
		$target = $this->post( 'movements', $fields )->get_data();
		$fields = array_merge(
			$fields,
			array(
				'id' => wp_generate_uuid4(),
				'type' => 'void',
				'amount' => '0',
				'voids' => $target['id'],
			)
		);
		$store = new Cash_Movement_Store();
		$refuse = static function ( $sql ) use ( $store ) {
			return 0 === strpos( $sql, 'UPDATE `' . $store->table_name() . '`' ) ? $sql . ' AND 1=0' : $sql;
		};
		add_filter( 'query', $refuse );
		try {
			$response = $this->post( 'movements', $fields );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'wcpos_movement_void_refused', $response->get_data()['code'] );
			$this->assertNull( $store->get( $fields['id'] ) );
			$this->assertSame( $target, $store->get( $target['id'] ) );
		} finally {
			remove_filter( 'query', $refuse );
		}
	}
}
