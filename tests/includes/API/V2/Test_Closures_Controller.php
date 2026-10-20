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
use WCPOS\WooCommercePOS\Services\Store_Reach;
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

	/** Parse the download with PHP's CSV reader, including embedded newlines.
	 *
	 * @param object $response Export response.
	 */
	private function export_csv( $response ): array {
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_raw_body();
		$this->assertSame( "\xEF\xBB\xBF", substr( $body, 0, 3 ) );
		$stream = fopen( 'php://memory', 'w+' );
		fwrite( $stream, substr( $body, 3 ) );
		rewind( $stream );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $stream );
		return $rows;
	}

	/** A manager authorizes the count and server expectations, not later edits or sales. */
	public function test_closure_approval_is_bound_to_counted_totals(): void {
		global $wpdb;
		$manager = self::factory()->user->create_and_get( array( 'user_pass' => 'approval-fixture' ) );
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		$threshold = static function () {
			return '5';
		};
		add_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		try {
			foreach ( array( 'same', 'cash', 'card', 'missing', 'missing_expected', 'sale', 'under', 'stale_under' ) as $case ) {
				$session = $this->closure_session();
				$body = $this->body( $session );
				$body['counted'] = array(
					'card' => '20.0000',
					'cash' => '80.0000',
				);
				if ( 'under' !== $case ) {
					$approved = $this->post(
						'sessions/' . $session['id'] . '/approve',
						array(
							'username' => $manager->user_login,
							'password' => 'approval-fixture',
							'counted' => array(
								'cash' => '080',
								'card' => '20',
							),
						)
					);
					$this->assertSame( 200, $approved->get_status() );
				}
				if ( 'sale' === $case ) {
					$this->closure_ledger( $session );
				} elseif ( 'missing_expected' === $case ) {
					$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'approved_expected' => null ), array( 'id' => $session['id'] ) );
				}
				if ( 'missing' === $case ) {
					$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'approved_counted' => null ), array( 'id' => $session['id'] ) );
				} elseif ( 'cash' === $case || 'card' === $case ) {
					$body['counted'][ $case ] = '70';
				} elseif ( 'under' === $case || 'stale_under' === $case ) {
					$body['counted']['cash'] = '100';
				}
				$body['till_expected']['cash'] = '80';
				$response = $this->post( 'closures', $body );
				$stored = ( new Register_Session_Store() )->get( $session['id'] );
				if ( 'same' === $case || 'under' === $case || 'stale_under' === $case ) {
					$this->assertSame( 201, $response->get_status(), $case );
					$this->assertSame( '100.0000', $response->get_data()['expected']['cash'] );
					if ( 'stale_under' === $case ) {
						$this->assertNull( $response->get_data()['approved_by'] );
						foreach ( array( 'approved_by', 'approved_counted', 'approved_expected' ) as $key ) {
							$this->assertNull( $stored[ $key ], $key );
						}
					}
				} else {
					$this->assertSame( 403, $response->get_status(), $case );
					$this->assertSame( 'wcpos_override_refused', $response->get_data()['code'] );
					$this->assertSame( 'counting', $stored['status'] );
					$this->assertSame( $manager->ID, $stored['approved_by'] );
					$this->assertNull( ( new Closure_Store() )->for_session( $session['id'] ) );
					if ( 'cash' === $case || 'sale' === $case ) {
						$this->assertSame( array( 'cash' => '100.0000' ), $stored['approved_expected'] );
						$this->assertSame(
							array(
								'cash' => '80.0000',
								'card' => '20.0000',
							),
							$stored['approved_counted']
						);
						$this->assertSame(
							200,
							$this->post(
								'sessions/' . $session['id'] . '/approve',
								array(
									'username' => $manager->user_login,
									'password' => 'approval-fixture',
									'counted' => $body['counted'],
								)
							)->get_status()
						);
						$closed = $this->post( 'closures', $body );
						$this->assertSame( 201, $closed->get_status() );
						if ( 'sale' === $case ) {
							$this->assertSame( '150.0000', $closed->get_data()['expected']['cash'] );
							$this->assertSame( '150.0000', ( new Register_Session_Store() )->get( $session['id'] )['approved_expected']['cash'] );
						}
					}
				}
			}
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		}
	}

	/** A printed Z is retained with a finding; the same unprinted close is refused. */
	public function test_printed_closure_records_missing_approval_instead_of_refusing(): void {
		$threshold = static function () {
			return '5';
		};
		add_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		try {
			foreach ( array( 'counting', 'closed' ) as $status ) {
				$session = $this->closure_session( null, $status );
				$this->closure_ledger( $session );
				$body = $this->body( $session );
				$body['counted'] = array( 'cash' => '100' );
				$refused = $this->post( 'closures', $body );
				$this->assertSame( 403, $refused->get_status(), $status );
				$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
				$this->assertSame( $status, ( new Register_Session_Store() )->get( $session['id'] )['status'] );
				$body['printed_at'] = $body['closed_at'];
				$response = $this->post( 'closures', $body );
				$this->assertSame( 201, $response->get_status(), $status );
				$row = $response->get_data();
				$this->assertSame(
					array(
						'variance' => '-50.0000',
						'threshold' => '5',
					),
					$row['findings']['approval_missing']
				);
				$this->assertArrayHasKey( 'total_mismatch', $row['findings'] );
				$this->assertSame( '100.0000', $row['counted']['cash'] );
				$this->assertSame( '150.0000', $row['expected']['cash'] );
				$this->assertNull( $row['approved_by'] );
				$this->assertSame( 'closed', ( new Register_Session_Store() )->get( $session['id'] )['status'] );
				$this->assertSame( $row['findings'], ( new Closure_Store() )->get( $row['id'] )['findings'] );
			}
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		}
	}

	/** A late sale must not change the already-derived closure approval snapshot. */
	public function test_closure_late_sale_preserves_snapshot_and_approver(): void {
		$manager = self::factory()->user->create();
		$session = ( new Register_Session_Store() )->approve( $this->closure_session(), $manager, array( 'cash' => '80' ) );
		$late_sale = function () use ( $session ) {
			$this->closure_ledger( $session );
			return '5';
		};
		add_filter( 'woocommerce_pos_session_variance_threshold', $late_sale );
		try {
			$response = $this->post( 'closures', array( 'counted' => array( 'cash' => '80' ) ) + $this->body( $session ) );
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $late_sale );
		}
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'cash' => '100.0000' ), $response->get_data()['expected'] );
		$this->assertSame( $manager, $response->get_data()['approved_by'] );
	}

	/** Only the closure owner acquires/releases its register lock, not its transition. */
	public function test_closure_transition_reuses_caller_register_lock(): void {
		$session = $this->closure_session();
		$locks = array();
		$observe = static function ( $sql ) use ( $session, &$locks ) {
			if ( false !== strpos( $sql, 'wcpos_register_' . $session['register_id'] ) ) {
				$locks[] = strstr( $sql, 'GET_LOCK' ) ? 'acquire' : 'release';
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$response = $this->post( 'closures', $this->body( $session ) );
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'acquire', 'release' ), $locks );
	}

	/** A stale snapshot must not close after approval or status changes. */
	public function test_closing_refuses_a_changed_approval_snapshot(): void {
		global $wpdb;
		$store = new Register_Session_Store();
		$manager = self::factory()->user->create();
		foreach ( array(
			'approved_by' => $manager + 1,
			'approved_counted' => '{"cash":"70"}',
			'approved_expected' => '{"cash":"90"}',
			'status' => 'closed',
		) as $key => $value ) {
			$session = $store->approve( $this->closure_session(), $manager, array( 'cash' => '80' ) );
			if ( 'status' === $key ) {
				$fields = array( 'status' => 'closed' );
				$fields['counted'] = array( 'cash' => '80' );
				$this->assertSame( 'closed', $store->transition( $session, $fields )['status'] );
			} else {
				$wpdb->update( $store->table_name(), array( $key => $value ), array( 'id' => $session['id'] ) );
			}
			$result = $store->transition(
				$session,
				array(
					'status' => 'closed',
					'counted' => array( 'cash' => '80' ),
				)
			);
			$this->assertWPError( $result, $key );
			$this->assertSame( 'status' === $key ? 'wcpos_session_transition_refused' : 'wcpos_override_refused', $result->get_error_code() );
			$this->assertSame( 'status' === $key ? 409 : 403, $result->get_error_data()['status'] );
			$this->assertSame( 'status' === $key ? 'closed' : 'counting', $store->get( $session['id'] )['status'] );
		}
	}

	/** Both session writes must wait for a competing register owner. */
	public function test_approval_and_status_respect_the_register_lock(): void {
		$session = $this->closure_session();
		$manager = self::factory()->user->create_and_get( array( 'user_pass' => 'approval-fixture' ) );
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock = 'wcpos_register_' . $session['register_id'];
		$this->assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) );
		try {
			foreach ( array( 'approve', 'status' ) as $route ) {
				$response = $this->post(
					'sessions/' . $session['id'] . '/' . $route,
					array(
						'username' => $manager->user_login,
						'password' => 'approval-fixture',
						'counted' => array( 'cash' => '80' ),
						'status' => 'closed',
						'at' => '2026-09-11T12:00:00Z',
					)
				);
				$this->assertSame( 500, $response->get_status(), $route );
				$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $session['id'] )['status'] );
			}
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}
	}

	/** Deferred documents must preserve the closed count and its expected snapshot. */
	public function test_deferred_closure_enforces_count_and_approval(): void {
		$store = new Register_Session_Store();
		$manager = self::factory()->user->create();
		$threshold = static function () {
			return '5';
		};
		add_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		try {
			foreach ( array( 'counted', 'expected' ) as $case ) {
				$session = $store->approve( $this->closure_session(), $manager, array( 'cash' => '80' ) );
				$body = $this->body( $session );
				$body['counted'] = array( 'cash' => '80' );
				$this->assertSame(
					200,
					$this->post(
						'sessions/' . $session['id'] . '/status',
						array(
							'status' => 'closed',
							'at' => $body['closed_at'],
							'counted' => $body['counted'],
						)
					)->get_status()
				);
				if ( 'expected' === $case ) {
					$this->closure_ledger( $session );
				} else {
					$body['counted']['cash'] = '70';
					$body['printed_at'] = $body['closed_at'];
				}
				$response = $this->post( 'closures', $body );
				$this->assertSame( 'counted' === $case ? 409 : 403, $response->get_status(), $case );
				$this->assertSame( 'counted' === $case ? 'wcpos_closure_counted_mismatch' : 'wcpos_override_refused', $response->get_data()['code'] );
				$this->assertNull( ( new Closure_Store() )->for_session( $session['id'] ) );
				$this->assertSame( $manager, $store->get( $session['id'] )['approved_by'] );
				if ( 'counted' === $case ) {
					$body['counted']['cash'] = '080.0000';
					$this->assertSame( 201, $this->post( 'closures', $body )->get_status() );
				}
			}
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		}
	}

	/** A refused close must not leave a cached stamp that suppresses the approved retry. */
	public function test_closure_refused_before_approval_can_be_retried(): void {
		$session = $this->closure_session();
		$this->closure_ledger( $session );
		$body = $this->body( $session );
		$body['counted'] = array( 'cash' => '80' );
		$manager = self::factory()->user->create_and_get( array( 'user_pass' => 'approval-fixture' ) );
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		$threshold = static function () {
			return '5';
		};
		add_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		try {
			$refused = $this->post( 'closures', $body );
			$this->assertSame( 403, $refused->get_status() );
			$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
			$approved = $this->post(
				'sessions/' . $session['id'] . '/approve',
				array(
					'username' => $manager->user_login,
					'password' => 'approval-fixture',
					'counted' => $body['counted'],
				)
			);
			$this->assertSame( 200, $approved->get_status() );
			$closed = $this->post( 'closures', $body );
			$this->assertSame( 201, $closed->get_status() );
			$this->assertSame( '150.0000', $closed->get_data()['expected']['cash'] );
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		}
	}

	/** An empty store still supplies the fixed schema, not a document lookup. */
	public function test_export_empty_store_returns_header_only(): void {
		// Arrange: no closure fixtures.
		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );

		// Assert.
		$this->assertSame( 1, count( $csv ) );
		$this->assertSame(
			array(
				'closure_number',
				'register_id',
				'register_name',
				'store_id',
				'store_name',
				'business_day',
				'opened_at_gmt',
				'closed_at_gmt',
				'closed_by',
				'closed_by_name',
				'currency',
				'timezone',
				'float_expected',
				'float_counted',
				'float_variance',
				'period_sales_total',
				'period_refunds_total',
				'perpetual_sales_total',
				'perpetual_refunds_total',
				'first_sale_counter',
				'last_sale_counter',
				'unsynced_count',
				'unsynced_total',
				'corrections_count',
			),
			$csv[0]
		);
	}

	/** Register ordering wins over global number or closing time ordering. */
	public function test_export_multiple_registers_orders_register_then_number(): void {
		// Arrange.
		$store = new Closure_Store();
		$first = $this->closure_session();
		$second = $this->closure_session();
		$registers = array( $first['register_id'], $second['register_id'] );
		sort( $registers, SORT_STRING );
		$lower = $first['register_id'] === $registers[0] ? $first : $second;
		$upper = $first['register_id'] === $registers[1] ? $first : $second;
		$store->create( $this->closure_fields( $upper, 1 ) );
		$store->create( $this->closure_fields( $lower, 2 ) );
		$store->create( $this->closure_fields( $this->closure_session( $registers[0] ), 10 ) );

		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );

		// Assert.
		$this->assertSame( 4, count( $csv ) );
		$this->assertSame( array( '2', '10', '1' ), array_column( array_slice( $csv, 1 ), 0 ) );
		$this->assertSame( array( $registers[0], $registers[0], $registers[1] ), array_column( array_slice( $csv, 1 ), 1 ) );
	}

	/** Recounts add metadata, never settled figures or extra CSV rows. */
	public function test_export_corrected_closure_preserves_recorded_figures(): void {
		// Arrange.
		$store = new Closure_Store();
		$fields = $this->closure_fields( $this->closure_session() );
		$fields['counted']['cash'] = '98.0000';
		$row = $store->create( $fields );
		$store->recount( $row, wp_generate_uuid4(), array( 'cash' => '110.0000' ), 'Recount' );
		$store->recount( $row, wp_generate_uuid4(), array( 'cash' => '120.0000' ), 'Recount again' );

		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );

		// Assert.
		$this->assertSame( 2, count( $csv ) );
		$data = array_combine( $csv[0], $csv[1] );
		$this->assertSame( '2', $data['corrections_count'] );
		$this->assertSame( '98.0000', $data['counted_cash'] );
		$this->assertSame( '100.0000', $data['expected_cash'] );
		$this->assertSame( '-2.0000', $data['variance_cash'] );
	}

	/** A reporting cashier does not own Settings. */
	public function test_export_without_manage_capability_returns_403(): void {
		// Arrange.
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos', false );
		// Act.
		$response = $this->get( 'closures/export' );
		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** Settings access cannot bypass the blind-count redaction boundary. */
	public function test_export_without_reports_capability_returns_403(): void {
		// Arrange.
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports', false );
		// Act.
		$response = $this->get( 'closures/export' );
		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** POS access remains the floor, even for an otherwise capable manager.
	 *
	 * The refusal comes from the baseline gate in API::rest_pre_dispatch(), which
	 * runs before the controller, so the code is `woocommerce_pos_rest_forbidden`
	 * rather than the controller's own `rest_forbidden`. That ordering is the
	 * point: the export is not exempted from the central POS-access gate just to
	 * make its error envelope uniform.
	 */
	public function test_export_without_access_capability_returns_403(): void {
		// Arrange.
		wp_get_current_user()->add_cap( 'access_woocommerce_pos', false );
		// Act.
		$response = $this->get( 'closures/export' );
		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_forbidden', $response->get_data()['code'] );
	}

	/** An extension's store scoping is an authorization boundary, not a list filter.
	 *
	 * Pro restricts a manager to its authorized stores through
	 * `woocommerce_pos_closures_list_args`, and Fiscal_Record_Store::resolve_document()
	 * enforces the same scope on a single document read. An export that paged the store
	 * directly would serve fiscal figures for stores the caller cannot reach by either
	 * existing path, so the scope is resolved from an empty base and applied to both
	 * passes. The caller's OWN query params are still ignored: the export is the whole
	 * set within the scope it is allowed, never a filtered view.
	 */
	public function test_export_honours_extension_store_scoping(): void {
		// Arrange: capture what a store with NO closures produces, before creating one.
		$empty_body = $this->get( 'closures/export' )->get_raw_body();
		( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$scope = static function ( $args ) {
			$args['store_id'] = 789;
			return $args;
		};

		// Act.
		add_filter( 'woocommerce_pos_closures_list_args', $scope );
		try {
			$scoped_response = $this->get( 'closures/export' );
			$scoped_body = $scoped_response->get_raw_body();
			$scoped = $this->export_csv( $scoped_response );
		} finally {
			remove_filter( 'woocommerce_pos_closures_list_args', $scope );
		}
		$unscoped = $this->export_csv( $this->get( 'closures/export' ) );

		// Assert: the scoped export is byte-identical to what a store with no closures
		// at all produces. Asserting indistinguishability rather than a row count means
		// no oracle survives — the variable tender, payment-method and tax-rate columns
		// are unioned from in-scope rows only, so a caller cannot learn that a closure
		// exists elsewhere from a column name, a row count or the content length.
		$this->assertSame( $empty_body, $scoped_body );
		$this->assertSame( 1, count( $scoped ) );
		// And the row is genuinely there once the scope lifts, so the test above is not
		// passing because the fixture failed to create anything.
		$this->assertSame( 2, count( $unscoped ) );
	}

	/** A malfunctioning scope filter refuses the export rather than serving it unscoped.
	 *
	 * The dangerous failure here is not an error, it is a silent success: degrading a
	 * broken filter to "no scope" would export every store's closures at exactly the
	 * moment something is wrong.
	 */
	public function test_export_refuses_a_non_array_scope_instead_of_serving_everything(): void {
		// Arrange.
		( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$broken = static function () {
			return 'not-an-array';
		};

		// Act.
		add_filter( 'woocommerce_pos_closures_list_args', $broken );
		try {
			$response = $this->get( 'closures/export' );
		} finally {
			remove_filter( 'woocommerce_pos_closures_list_args', $broken );
		}

		// Assert: refused, and nothing was served.
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpos_closure_export_failed', $response->get_data()['code'] );
	}

	/** A legacy closure with no business day is still exported, with an empty cell.
	 *
	 * `business_day` is CHAR(10) NULL and Activator::upgrade_business_days() back-fills
	 * it in batches of 100, so a store mid-upgrade holds both stamped and unstamped
	 * rows. This export promises every closure the store has ever written, so the
	 * unstamped ones must appear — a blank cell is a missing stamp, but a missing ROW
	 * would be a silently incomplete fiscal record. Pinned separately from the frozen
	 * presentation test, where the empty day was only incidental.
	 */
	public function test_export_includes_a_legacy_closure_without_a_business_day(): void {
		// Arrange: two closures, one back-dated to the unstamped legacy shape (on its session row).
		global $wpdb;
		$store = new Closure_Store();
		$legacy = $store->create( $this->closure_fields( $this->closure_session() ) );
		$stamped = $store->create( $this->closure_fields( $this->closure_session() ) );
		$sessions = ( new Register_Session_Store() )->table_name();
		$wpdb->update( $sessions, array( 'business_day' => null ), array( 'id' => $legacy['session_id'] ) );
		$wpdb->update( $sessions, array( 'business_day' => '2026-09-11' ), array( 'id' => $stamped['session_id'] ) );

		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );

		// Assert: both rows present; the legacy one carries an empty day, not an invented one.
		$this->assertSame( 3, count( $csv ) );
		$rows = array();
		foreach ( array_slice( $csv, 1 ) as $row ) {
			$cells = array_combine( $csv[0], $row );
			$rows[ $cells['register_id'] ] = $cells['business_day'];
		}
		$this->assertSame( '', $rows[ $legacy['register_id'] ] );
		$this->assertSame( '2026-09-11', $rows[ $stamped['register_id'] ] );
	}

	/** A malformed store VALUE is an unreadable restriction, not an absent one.
	 *
	 * The container being an array is not enough. Today Closure_Store::list() renders
	 * the predicate in SQL, where a bad value matches little and never a NULL store —
	 * but that is incidental to the store, so the route states the rule itself rather
	 * than inheriting it.
	 */
	public function test_export_refuses_a_malformed_store_scope(): void {
		// Arrange.
		( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		// Two families, both of which a looser check waves through.
		// Stringify: true and 1.0 both become "1" under is_scalar() + (string).
		// Coerce: is_numeric() denies booleans but happily rewrites '1e2' into store
		// 100 and ' 1' into store 1 — an unreadable restriction silently becoming a
		// different, entirely plausible-looking one, with no log line because it
		// "passed". Both families must refuse, not be corrected.
		foreach ( array(
			'invalid',
			// Pins array_key_exists() over isset(): isset() is FALSE for null, so the
			// key would be skipped as absent, list() would receive store_id => null,
			// render `store_id IS NULL`, and export ONLY unassigned closures — an
			// unreadable restriction silently becoming a real one.
			null,
			true,
		) as $value ) {
			$broken = static function ( $args ) use ( $value ) {
				$args['store_id'] = $value;
				return $args;
			};
			// Act.
			add_filter( 'woocommerce_pos_closures_list_args', $broken );
			try {
				$response = $this->get( 'closures/export' );
			} finally {
				remove_filter( 'woocommerce_pos_closures_list_args', $broken );
			}
			// Assert.
			$this->assertSame( 500, $response->get_status(), wp_json_encode( $value ) );
		}
	}

	/** An empty allowed-store list is a real restriction and exports nothing. */
	public function test_export_empty_store_scope_exports_nothing(): void {
		// Arrange.
		$empty_body = $this->get( 'closures/export' )->get_raw_body();
		( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$none = static function ( $args ) {
			$args['store_id'] = array();
			return $args;
		};

		// Act.
		add_filter( 'woocommerce_pos_closures_list_args', $none );
		try {
			$response = $this->get( 'closures/export' );
		} finally {
			remove_filter( 'woocommerce_pos_closures_list_args', $none );
		}

		// Assert: accepted, and indistinguishable from a store with no closures.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $empty_body, $response->get_raw_body() );
	}

	/** The export's own paging and ordering win over anything the scope supplies.
	 *
	 * The scope is merged FIRST and the export's page, per_page and number_order
	 * second, so they override. Reverse the array_merge() arguments and an extension
	 * could start the walk at page 5, shrink per_page, or reorder the file — each of
	 * which silently truncates or rearranges a fiscal export that claims to be the
	 * whole set. Nothing else in the suite would notice, because no other test has a
	 * filter that supplies those keys.
	 */
	public function test_export_paging_and_order_beat_the_scope_filter(): void {
		// Arrange: three closures across two registers, as the ordering test uses.
		$store = new Closure_Store();
		$first = $this->closure_session();
		$second = $this->closure_session();
		$registers = array( $first['register_id'], $second['register_id'] );
		sort( $registers, SORT_STRING );
		$lower = $first['register_id'] === $registers[0] ? $first : $second;
		$upper = $first['register_id'] === $registers[1] ? $first : $second;
		$store->create( $this->closure_fields( $upper, 1 ) );
		$store->create( $this->closure_fields( $lower, 2 ) );
		$store->create( $this->closure_fields( $this->closure_session( $registers[0] ), 10 ) );
		// A filter that tries to take over paging and ordering.
		$hostile = static function ( $args ) {
			$args['page'] = 5;
			$args['per_page'] = 1;
			$args['number_order'] = true;
			return $args;
		};

		// Act.
		add_filter( 'woocommerce_pos_closures_list_args', $hostile );
		try {
			$csv = $this->export_csv( $this->get( 'closures/export' ) );
		} finally {
			remove_filter( 'woocommerce_pos_closures_list_args', $hostile );
		}

		// Assert: all three rows, still in register-then-number order.
		$this->assertSame( 4, count( $csv ) );
		$this->assertSame( array( '2', '10', '1' ), array_column( array_slice( $csv, 1 ), 0 ) );
	}

	/** A caller cannot narrow the export with query params; it is the whole allowed set. */
	public function test_export_ignores_caller_supplied_list_filters(): void {
		// Arrange: two registers, one closure each.
		( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$other = $this->closure_session();
		( new Closure_Store() )->create( $this->closure_fields( $other ) );

		// Act: ask for one register only.
		$csv = $this->export_csv( $this->get( 'closures/export', array( 'register_id' => $other['register_id'] ) ) );

		// Assert: both rows are still exported.
		$this->assertSame( 3, count( $csv ) );
	}

	/** The admin navigation carries query authentication, not protocol headers. */
	public function test_export_admin_query_without_protocol_returns_download(): void {
		// Arrange.
		$request = new \WP_REST_Request( 'GET', '/wcpos/v2/closures/export' );
		$request->set_query_params(
			array(
				'wcpos' => '1',
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
		// Act.
		$response = $this->server->dispatch( $request );
		// Assert: raw CSV proves this reached export, not the UUID or list branch.
		$this->assertSame( 1, count( $this->export_csv( $response ) ) );
	}

	/** Browsers receive an attachment with a byte-accurate length. */
	public function test_export_response_has_csv_download_headers(): void {
		// Arrange: an empty export is a valid file.
		// Act.
		$response = $this->get( 'closures/export' );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^attachment; filename="wcpos-closures-.+-[0-9]{4}-[0-9]{2}-[0-9]{2}\.csv"$/', $headers['Content-Disposition'] );
		$this->assertSame( (string) strlen( $response->get_raw_body() ), $headers['Content-Length'] );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
	}

	/** Quoting and spreadsheet formula protection apply to frozen merchant text.
	 *
	 * The register name is the merchant-controlled string the export freezes onto
	 * the row, so it is the one the test can set directly. It exercises all three
	 * hazards at once: a leading `=` that a spreadsheet would execute, an embedded
	 * comma and quote that must be CSV-quoted, and a newline inside a cell.
	 */
	public function test_export_formula_register_name_round_trips_safely(): void {
		// Arrange.
		$name = '=Café, "North"' . "\nAnnex";
		$this->use_multi_register_repository();
		$register_id = $this->create_register(
			array(
				'name' => $name,
				'store_id' => 456,
			)
		)['id'];
		$this->closure_registers[] = $register_id;
		( new Closure_Store() )->create( $this->closure_fields( $this->closure_session( $register_id ) ) );
		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );
		// Assert: the formula is defused with a leading quote, and the comma, quote
		// and newline survive the round trip through the CSV parser intact.
		$data = array_combine( $csv[0], $csv[1] );
		$this->assertSame( "'" . $name, $data['register_name'] );
	}

	/** Currency and timezone are recorded facts, not current store preferences. */
	public function test_export_changed_settings_preserves_frozen_presentation(): void {
		// Arrange.
		$currency = 'EUR';
		$timezone = 'Europe/Madrid';
		$currency_filter = static function () use ( &$currency ) {
			return $currency;
		};
		$timezone_filter = static function () use ( &$timezone ) {
			return $timezone;
		};
		add_filter( 'pre_option_woocommerce_currency', $currency_filter );
		add_filter( 'pre_option_timezone_string', $timezone_filter );
		try {
			$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
			$this->assertSame( 'EUR', $row['breakdowns']['currency'] );
			$this->assertSame( 'Europe/Madrid', $row['breakdowns']['timezone'] );
			$currency = 'USD';
			$timezone = 'America/New_York';
			// Act.
			$csv = $this->export_csv( $this->get( 'closures/export' ) );
			// Assert.
			$data = array_combine( $csv[0], $csv[1] );
			$this->assertSame( 'EUR', $data['currency'] );
			$this->assertSame( 'Europe/Madrid', $data['timezone'] );
			$this->assertSame( '', $data['business_day'] );
			$this->assertSame( 'Closure fixture', $data['register_name'] );
			$this->assertSame( $row['breakdowns']['labels']['closed_by_name'], $data['closed_by_name'] );
		} finally {
			remove_filter( 'pre_option_woocommerce_currency', $currency_filter );
			remove_filter( 'pre_option_timezone_string', $timezone_filter );
		}
	}

	/** Later pages extend a deterministic header; collisions and absence stay distinct. */
	public function test_export_multiple_pages_preserves_union_and_distinct_columns(): void {
		// Arrange. Clone stored session rows to exercise paging without 101 session transactions.
		global $wpdb;
		$store = new Closure_Store();
		$row = $store->create( $this->closure_fields( $this->closure_session() ) );
		$table = ( new Register_Session_Store() )->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table name from Register_Session_Store::table_name(); the id is prepared.
		$stored = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $row['session_id'] ), ARRAY_A );
		for ( $number = 2; $number <= 101; ++$number ) {
			$copy = $stored;
			$copy['id'] = wp_generate_uuid4();
			$copy['closure_id'] = wp_generate_uuid4();
			$copy['number'] = $number;
			if ( 101 === $number ) {
				$document = json_decode( $stored['closure'], true );
				$breakdowns = $row['breakdowns'];
				$breakdowns['payment_methods'] = array(
					array(
						'method' => 'z_card',
						'sales' => '8.0000',
						'refunds' => '0.0000',
					),
					array(
						'method' => 'a_cash',
						'sales' => '2.0000',
					),
				);
				$breakdowns['tax_rates'] = array(
					'VAT A' => array( 'net' => '1.0000' ),
					'VAT-A' => array( 'net' => '2.0000' ),
					'VAT_A_2' => array( 'net' => '3.0000' ),
				);
				$breakdowns['opening_float'] = array(
					'expected' => '100.0000',
					'counted' => '99.0000',
					'variance' => '-1.0000',
				);
				$document['breakdowns'] = $breakdowns;
				$document['counted'] = array(
					'cash' => '101.0000',
					'voucher' => '0.0000',
				);
				$copy['closure'] = wp_json_encode( $document );
			}
			$this->assertSame( 1, $wpdb->insert( $table, $copy ) );
		}
		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );
		// Assert.
		$this->assertSame( 102, count( $csv ) );
		$first = array_combine( $csv[0], $csv[1] );
		$last = array_combine( $csv[0], $csv[101] );
		$this->assertSame( '101', $last['closure_number'] );
		$this->assertSame( '', $first['counted_voucher'] );
		$this->assertSame( '0.0000', $last['counted_voucher'] );
		$this->assertSame( '', $last['expected_voucher'] );
		$this->assertSame( '-1.0000', $last['float_variance'] );
		$this->assertSame( '8.0000', $last['tender_z_card_sales'] );
		$this->assertSame( '0.0000', $last['tender_z_card_refunds'] );
		$this->assertSame( '', $last['tender_a_cash_refunds'] );
		$this->assertSame( '1.0000', $last['tax_vat_a_net'] );
		$this->assertSame( '2.0000', $last['tax_vat_a_2_net'] );
		$this->assertSame( '3.0000', $last['tax_vat_a_2_2_net'] );
		$this->assertSame( count( $csv[0] ), count( array_unique( $csv[0] ) ) );
		$this->assertSame(
			array( 'counted_cash', 'counted_voucher', 'expected_cash', 'expected_voucher', 'variance_cash', 'variance_voucher', 'tender_a_cash_sales', 'tender_a_cash_refunds', 'tender_z_card_sales', 'tender_z_card_refunds', 'tax_vat_a_net', 'tax_vat_a_tax', 'tax_vat_a_gross', 'tax_vat_a_2_net', 'tax_vat_a_2_tax', 'tax_vat_a_2_gross', 'tax_vat_a_2_2_net', 'tax_vat_a_2_2_tax', 'tax_vat_a_2_2_gross' ),
			array_slice( $csv[0], 15, 19 )
		);
	}

	/** A shared tax display name must not overwrite a distinct stored rate. */
	public function test_export_duplicate_tax_names_preserves_every_rate(): void {
		// Arrange.
		$store = new Closure_Store();
		$session = $this->closure_session();
		$fields = $this->closure_fields( $session );
		$fields['breakdowns']['tax_rates'] = array(
			'rate_a' => array(
				'name' => 'VAT',
				'net' => '10.0000',
			),
			'rate_b' => array(
				'name' => 'VAT',
				'net' => '20.0000',
			),
		);
		$store->create( $fields );
		$fields = $this->closure_fields( $this->closure_session( $session['register_id'] ), 2 );
		$fields['breakdowns']['tax_rates'] = array(
			array(
				'name' => 'VAT',
				'net' => '30.0000',
			),
			array(
				'name' => 'VAT',
				'net' => '40.0000',
			),
		);
		$store->create( $fields );

		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );

		// Assert.
		$this->assertSame( 3, count( $csv ) );
		$columns = array( 'tax_vat_net', 'tax_vat_2_net', 'tax_vat_3_net', 'tax_vat_4_net' );
		$first = array_combine( $csv[0], $csv[1] );
		$second = array_combine( $csv[0], $csv[2] );
		$this->assertSame( array( '', '', '10.0000', '20.0000' ), array_values( array_intersect_key( $first, array_flip( $columns ) ) ) );
		$this->assertSame( array( '30.0000', '40.0000', '', '' ), array_values( array_intersect_key( $second, array_flip( $columns ) ) ) );
	}

	/** Numeric tax IDs are map identities, not list positions. */
	public function test_export_numeric_tax_keys_keeps_rate_columns_aligned(): void {
		// Arrange.
		$store = new Closure_Store();
		$session = $this->closure_session();
		$fields = $this->closure_fields( $session );
		$fields['breakdowns']['tax_rates'] = array(
			12 => array(
				'name' => 'VAT',
				'net' => '10.0000',
			),
			36 => array(
				'name' => 'VAT',
				'net' => '20.0000',
			),
		);
		$store->create( $fields );
		$fields = $this->closure_fields( $this->closure_session( $session['register_id'] ), 2 );
		$fields['breakdowns']['tax_rates'] = array(
			36 => array(
				'name' => 'VAT',
				'net' => '30.0000',
			),
		);
		$store->create( $fields );

		// Act.
		$csv = $this->export_csv( $this->get( 'closures/export' ) );

		// Assert.
		$first = array_combine( $csv[0], $csv[1] );
		$second = array_combine( $csv[0], $csv[2] );
		$this->assertSame( '10.0000', $first['tax_vat_net'] );
		$this->assertSame( '20.0000', $first['tax_vat_2_net'] );
		$this->assertSame( '', $second['tax_vat_net'] );
		$this->assertSame( '30.0000', $second['tax_vat_2_net'] );
	}

	/** Uppercase UUIDs cannot bypass the write filter's normalized identity checks. */
	public function test_closure_create_filtered_session_returns_404_without_write(): void {
		$session = $this->closure_session();
		$body = $this->body( $session );
		$body['id'] = strtoupper( $body['id'] );
		$body['session_id'] = strtoupper( $body['session_id'] );
		$filter = static function ( $row, $request ) use ( $session, $body ) {
			return $row && $session['id'] === $row['id'] && $session['id'] === $request['session_id'] && strtolower( $body['id'] ) === $request['id'] ? null : $row;
		};
		add_filter( 'woocommerce_pos_session_row', $filter, 10, 2 );
		try {
			$response = $this->post( 'closures', $body );
		} finally {
			remove_filter( 'woocommerce_pos_session_row', $filter );
		}
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_session_not_found', $response->get_data()['code'] );
		$this->assertNull( ( new Closure_Store() )->get( $body['id'] ) );
		$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $session['id'] )['status'] );
	}

	/** Store reach is enforced before the closure or session is written. */
	public function test_closure_create_outside_store_reach_returns_404_without_write(): void {
		$session = $this->closure_session();
		$body = $this->body( $session );
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			$response = $this->post( 'closures', $body );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_session_not_found', $response->get_data()['code'] );
		$this->assertNull( ( new Closure_Store() )->get( $body['id'] ) );
		$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $session['id'] )['status'] );
	}

	/** Unknown sessions have the same not-found response as hidden sessions. */
	public function test_closure_create_unknown_session_returns_404_without_write(): void {
		$body = $this->body( $this->closure_session() );
		$body['session_id'] = wp_generate_uuid4();
		$response = $this->post( 'closures', $body );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_session_not_found', $response->get_data()['code'] );
		$this->assertNull( ( new Closure_Store() )->get( $body['id'] ) );
	}

	/** Creation uses one authorization decision for the session and returned row. */
	public function test_closure_create_resolves_store_reach_once(): void {
		$body = $this->body( $this->closure_session() );
		$calls = 0;
		$filter = static function () use ( &$calls ) {
			++$calls;
			return array( 'store_id' => array( 456 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			$response = $this->post( 'closures', $body );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, $calls );
	}

	/** Incomplete bodies cannot distinguish hidden closure IDs from absent ones. */
	public function test_closure_replay_incomplete_body_hidden_and_absent_match(): void {
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$body = $this->body( $this->closure_session() );
		unset( $body['software_version'] );
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			$absent = $this->post( 'closures', $body );
			$body['id'] = $row['id'];
			$hidden = $this->post( 'closures', $body );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
		$this->assertSame( 400, $absent->get_status() );
		$this->assertSame( 'rest_invalid_param', $absent->get_data()['code'] );
		$this->assertSame( $absent->get_status(), $hidden->get_status() );
		$this->assertSame( $absent->get_data()['code'], $hidden->get_data()['code'] );
	}

	/** Id-only replay checks the stored closure, not a caller-supplied session. */
	public function test_closure_replay_outside_store_reach_returns_404(): void {
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$this->assertSame( 456, $row['store_id'] );
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			$response = $this->post( 'closures', array( 'id' => $row['id'] ) );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_closure_not_found', $response->get_data()['code'] );
		$this->assertSame( $row, ( new Closure_Store() )->get( $row['id'] ) );
	}

	/** A closure arriving after the first lookup must not bypass store reach. */
	public function test_closure_create_concurrent_replay_outside_store_reach_returns_404(): void {
		global $wpdb;
		$session = $this->closure_session();
		$body = $this->body( $session );
		$other = $this->closure_session();
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'store_id' => 789 ), array( 'id' => $other['id'] ) );
		$fields = $this->closure_fields( $other );
		$fields['id'] = $body['id'];
		$insert = static function ( $row ) use ( $fields ) {
			( new Closure_Store() )->create( $fields );
			return $row;
		};
		$scope = static function () {
			return array( 'store_id' => array( 456 ) );
		};
		add_filter( 'woocommerce_pos_session_row', $insert );
		add_filter( Store_Reach::FILTER, $scope );
		try {
			$response = $this->post( 'closures', $body );
		} finally {
			remove_filter( 'woocommerce_pos_session_row', $insert );
			remove_filter( Store_Reach::FILTER, $scope );
		}
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_closure_not_found', $response->get_data()['code'] );
		$this->assertSame( 789, ( new Closure_Store() )->get( $body['id'] )['store_id'] );
		$this->assertSame( $session, ( new Register_Session_Store() )->get( $session['id'] ) );
		$this->assertNull( ( new Closure_Store() )->for_session( $session['id'] ) );
	}

	/** An absent id-only replay is indistinguishable from an out-of-reach one. */
	public function test_closure_replay_unknown_id_returns_404_but_full_body_creates(): void {
		$body = $this->body( $this->closure_session() );
		$response = $this->post( 'closures', array( 'id' => $body['id'] ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_closure_not_found', $response->get_data()['code'] );
		$this->assertNull( ( new Closure_Store() )->get( $body['id'] ) );
		$this->assertSame( 201, $this->post( 'closures', $body )->get_status() );
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
		$this->assertArrayHasKey( 'checksum', $first->get_data() );
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

	/** Calendar-invalid business days are rejected before writing a closure. */
	public function test_closure_business_day_invalid_calendar_date_returns_400(): void {
		$body = $this->body( $this->closure_session() );
		foreach ( array( '2026-02-31', '0000-00-00', '2026-02-29' ) as $bad ) {
			$body['business_day'] = $bad;
			$response = $this->post( 'closures', $body );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		}
	}

	/** Both date filters reject calendar-invalid days rather than normalizing them. */
	public function test_closure_date_filters_invalid_calendar_date_returns_400(): void {
		foreach ( array( 'after', 'before' ) as $key ) {
			foreach ( array( '2026-02-31', '0000-00-00', '2026-02-29' ) as $bad ) {
				$response = $this->get( 'closures', array( $key => $bad ) );
				$this->assertSame( 400, $response->get_status() );
				$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
			}
		}
		$this->assertSame( 200, $this->get( 'closures', array( 'after' => '2024-02-29' ) )->get_status() );
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
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => null ), array( 'id' => $session['id'] ) );
		$this->assertSame( array( $row['id'] ), array_column( $this->get( 'closures', $args )->get_data(), 'id' ) );
	}

	/** Date-only bounds include the whole day; timestamp bounds retain their precision. */
	public function test_closure_date_filters_date_only_and_timestamp_boundaries(): void {
		global $wpdb;
		$register_id = null;
		$ids = array();
		foreach ( array(
			array( null, '2026-09-12T00:00:00Z' ),
			array( null, '2026-09-12T15:00:00Z' ),
			array( '2026-09-12', '2026-09-12T15:00:00Z' ),
			array( null, '2026-09-13T00:00:00Z' ),
			array( '2026-09-13', '2026-09-13T00:00:00Z' ),
		) as $index => $case ) {
			$session = $this->closure_session( $register_id );
			$register_id = $session['register_id'];
			$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => $case[0] ), array( 'id' => $session['id'] ) );
			$body = $this->body( $session, $index + 1 );
			$body['closed_at'] = $case[1];
			$response = $this->post( 'closures', $body );
			$this->assertSame( 201, $response->get_status() );
			$ids[] = $response->get_data()['id'];
		}

		foreach ( array(
			array( '2026-09-12', '2026-09-12', array( $ids[2], $ids[1], $ids[0] ) ),
			array( '2026-09-13', '2026-09-13', array( $ids[4], $ids[3] ) ),
			array( '2026-09-11', '2026-09-11', array() ),
			array( '2026-09-12T00:00:00Z', '2026-09-12T14:59:59Z', array( $ids[2], $ids[0] ) ),
			array( '2026-09-12T15:00:00Z', '2026-09-12T15:00:00Z', array( $ids[2], $ids[1] ) ),
			array( '2026-09-12T15:00:01Z', '2026-09-12T23:59:59Z', array( $ids[2] ) ),
		) as $case ) {
			$args = array(
				'register_id' => $register_id,
				'after' => $case[0],
				'before' => $case[1],
			);
			$response = $this->get( 'closures', $args );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $case[2], array_column( $response->get_data(), 'id' ) );
		}
	}

	/** Offset timestamps use their local date for stamps and their GMT instant otherwise. */
	public function test_closure_date_filters_offsets_preserve_local_business_day(): void {
		global $wpdb;
		$register_id = null;
		$ids = array();
		foreach ( array(
			array( '2026-09-11', '2026-09-11T22:30:00Z' ),
			array( '2026-09-12', '2026-09-11T22:30:00Z' ),
			array( null, '2026-09-11T22:29:59Z' ),
			array( null, '2026-09-11T22:30:00Z' ),
		) as $index => $case ) {
			$session = $this->closure_session( $register_id );
			$register_id = $session['register_id'];
			$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => $case[0] ), array( 'id' => $session['id'] ) );
			$body = $this->body( $session, $index + 1 );
			$body['closed_at'] = $case[1];
			$response = $this->post( 'closures', $body );
			$this->assertSame( 201, $response->get_status() );
			$ids[] = $response->get_data()['id'];
		}
		foreach ( array(
			array( 'after', '2026-09-12T00:30:00+02:00', array( $ids[1], $ids[3] ) ),
			array( 'before', '2026-09-11T23:30:00-02:00', array( $ids[0], $ids[2], $ids[3] ) ),
			array( 'before', '2026-09-12T00:29:59+02:00', array( $ids[0], $ids[1], $ids[2] ) ),
		) as $case ) {
			$response = $this->get(
				'closures',
				array(
					'register_id' => $register_id,
					$case[0] => $case[1],
				)
			);
			$this->assertSame( 200, $response->get_status() );
			$this->assertEqualsCanonicalizing( $case[2], array_column( $response->get_data(), 'id' ) );
		}
	}

	/** A closure request cannot supply a stamp missing from its session. */
	public function test_closure_business_day_unstamped_session_ignores_request_stamp(): void {
		$session = $this->closure_session();
		$this->assertNull( $session['business_day'] );
		$body = $this->body( $session );
		$body['business_day'] = '2026-09-11';

		$response = $this->post( 'closures', $body );

		$this->assertSame( 201, $response->get_status() );
		$row = $response->get_data();
		$this->assertNull( $row['business_day'] );
		$this->assertNull( ( new Closure_Store() )->get( $row['id'] )['business_day'] );
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
		$this->assertArrayNotHasKey( 'checksum', $record );
		$stored = ( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->get( $record['id'] );
		$this->assertSame( array( 'cash' => '-2.0000' ), $stored['payload']['variance'] );
		$this->assertSame( 64, strlen( $stored['checksum'] ) );
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
		\WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture::sale( $order );
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
					'cash' => '50.0000',
					'card' => '30.0000',
				),
				'sales_delta' => '80.0000',
				'refunds_delta' => '0.0000',
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
		$this->assertSame( 404, $this->post( 'closures', array_merge( $body, array( 'session_id' => wp_generate_uuid4() ) ) )->get_status() );
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
		// The counters the till needs to mint the next number still come back; the
		// perpetual totals are report figures (closure-as-ruled §Storage) and do not.
		$this->assertSame( 1, $row['number'] );
		$this->assertArrayNotHasKey( 'perpetual_sales_total', $row );
		$cashier->add_cap( 'view_woocommerce_pos_reports' );
		// wp_set_current_user() is a no-op for the same id; re-set so the cached caps reload.
		wp_set_current_user( 0 );
		wp_set_current_user( $cashier->ID );
		$this->assertArrayHasKey( 'expected', $this->post( 'closures', array( 'id' => $id ) )->get_data() );
	}

	/** Every expected-side figure is report-only on create and replay; the till's own facts stay. */
	public function test_closure_create_without_reports_capability_redacts_every_expected_side_figure(): void {
		// Arrange.
		$stripped = array( 'expected', 'till_expected', 'variance', 'period_sales_total', 'period_refunds_total', 'perpetual_sales_total', 'perpetual_refunds_total', 'breakdowns', 'findings' );
		$kept = array( 'counted', 'number', 'printed_number', 'opened_at_gmt', 'closed_at_gmt', 'received_at_gmt', 'opened_by', 'closed_by', 'approved_by', 'first_sale_counter', 'last_sale_counter', 'first_receipt_id', 'last_receipt_id', 'unsynced_count', 'unsynced_total', 'software_version', 'print_count', 'last_printed_at_gmt' );
		$session = $this->closure_session();
		$body = $this->body( $session );
		$cashier = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$cashier->add_cap( 'access_woocommerce_pos' );
		$cashier->add_cap( 'manage_woocommerce_pos_cash' );
		wp_set_current_user( $cashier->ID );

		// Act.
		$created = $this->post( 'closures', $body );
		$replay = $this->post( 'closures', array( 'id' => $body['id'] ) );

		// Assert.
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 200, $replay->get_status() );
		foreach ( array(
			'create' => $created->get_data(),
			'replay' => $replay->get_data(),
		) as $label => $row ) {
			foreach ( $stripped as $field ) {
				$this->assertArrayNotHasKey( $field, $row, $label . ': ' . $field );
			}
			foreach ( $kept as $field ) {
				$this->assertArrayHasKey( $field, $row, $label . ': ' . $field );
			}
		}
		$manager = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		foreach ( array( 'access_woocommerce_pos', 'manage_woocommerce_pos_cash', 'view_woocommerce_pos_reports' ) as $cap ) {
			$manager->add_cap( $cap );
		}
		wp_set_current_user( $manager->ID );
		$full = $this->post( 'closures', array( 'id' => $body['id'] ) )->get_data();
		foreach ( array_merge( $stripped, $kept ) as $field ) {
			$this->assertArrayHasKey( $field, $full, 'manager: ' . $field );
		}
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
			$this->assertNull( ( new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store() )->get( $session['id'], $movement['id'] ) );
			$this->assertSame( $closure, ( new Closure_Store() )->get( $closure['id'] ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}
	/** Item, print and recount deny before reading or changing fiscal figures. */
	public function test_closure_item_outside_store_reach_reads_as_absent(): void {
		// Arrange.
		$store = new Closure_Store();
		$row = $store->create( $this->closure_fields( $this->closure_session() ) );
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			foreach ( array( '', '/print', '/recount' ) as $suffix ) {
				// Act.
				$absent_route = 'closures/' . wp_generate_uuid4() . $suffix;
				$route = 'closures/' . $row['id'] . $suffix;
				$body = array(
					'id' => wp_generate_uuid4(),
					'counted' => array( 'cash' => '102' ),
					'reason' => 'Recount',
				);
				$absent = '' === $suffix ? $this->get( $absent_route ) : $this->post( $absent_route, $body );
				$response = '' === $suffix ? $this->get( $route ) : $this->post( $route, $body );
				// Assert.
				$this->assertSame( $absent->get_status(), $response->get_status() );
				$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
			}
			$this->assertSame( 0, $store->get( $row['id'] )['print_count'] );
			$this->assertSame(
				array(),
				( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->list(
					array(
						'closure_id' => $row['id'],
						'type' => 'recount',
					)
				)
			);
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}

	/** List and last intersect caller filters with store reach. */
	public function test_closure_list_and_last_are_narrowed_to_store_reach(): void {
		// Arrange.
		$session = $this->closure_session();
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			// Act.
			$list = $this->get( 'closures', array( 'store_id' => 456 ) );
			$last = $this->get( 'closures/last', array( 'register_id' => $session['register_id'] ) );
			// Assert.
			$this->assertSame( 200, $list->get_status() );
			$this->assertSame( array(), $list->get_data() );
			$this->assertSame( 200, $last->get_status() );
			$this->assertNull( $last->get_data() );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}

	/** Mid-fold, /last serves a newer closure only a legacy table holds unless its store is out of reach or its date is filtered out; once latched, the session row. */
	public function test_closure_last_while_fold_is_pending_returns_newer_legacy_closure_in_reach(): void {
		// Arrange.
		global $wpdb;
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$second = $this->closure_session( $row['register_id'] );
		$folded = $wpdb->prefix . Closure_Store::LEGACY_TABLE . '_folded';
		$closure_id = wp_generate_uuid4();
		$args = array( 'register_id' => $row['register_id'] );
		$reach = static function () {
			return array( 'store_id' => array( 456 ) );
		};
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$folded} ( id CHAR(36) NOT NULL, register_id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, store_id BIGINT NULL, number BIGINT NOT NULL, business_day CHAR(10) NULL, closed_at_gmt DATETIME NOT NULL, expected LONGTEXT NOT NULL, till_expected LONGTEXT NOT NULL, counted LONGTEXT NOT NULL, variance LONGTEXT NOT NULL, perpetual_sales_total DECIMAL(19,4) NOT NULL, breakdowns LONGTEXT NOT NULL, findings LONGTEXT NULL, PRIMARY KEY  (id) )" ) );
			$this->assertSame(
				1,
				$wpdb->insert(
					$folded,
					array(
						'id' => $closure_id,
						'register_id' => $second['register_id'],
						'session_id' => $second['id'],
						'store_id' => 456,
						'number' => 2,
						'business_day' => '2026-09-12',
						'closed_at_gmt' => '2026-09-12 12:00:00',
						'expected' => '{"cash":"150.0000"}',
						'till_expected' => '{"cash":"100.0000"}',
						'counted' => '{"cash":"101.0000"}',
						'variance' => '{"cash":"-49.0000"}',
						'perpetual_sales_total' => '80',
						'breakdowns' => '{"transaction_count":0}',
					)
				)
			);

			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			$pending = $this->get( 'closures/last', $args )->get_data();
			$before = $this->get( 'closures/last', $args + array( 'before' => '2026-09-11' ) )->get_data();
			$after = $this->get( 'closures/last', $args + array( 'after' => '2026-09-12' ) )->get_data();
			$none = $this->get( 'closures/last', $args + array( 'before' => '2026-09-10' ) )->get_data();
			$this->assertSame( 1, $wpdb->update( $folded, array( 'store_id' => 789 ), array( 'id' => $closure_id ) ) );
			add_filter( Store_Reach::FILTER, $reach );
			$hidden = $this->get( 'closures/last', $args )->get_data();
			remove_filter( Store_Reach::FILTER, $reach );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$latched = $this->get( 'closures/last', $args )->get_data();

			// Assert.
			$this->assertSame( array( $closure_id, 2 ), array( $pending['id'], $pending['number'] ) );
			$this->assertSame( array( $row['id'], 1 ), array( $before['id'], $before['number'] ) );
			$this->assertSame( array( $closure_id, 2 ), array( $after['id'], $after['number'] ) );
			$this->assertNull( $none );
			$this->assertSame( array( $row['id'], 1 ), array( $hidden['id'], $hidden['number'] ) );
			$this->assertSame( array( $row['id'], 1 ), array( $latched['id'], $latched['number'] ) );
		} finally {
			remove_filter( Store_Reach::FILTER, $reach );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$folded}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		}
	}

	/** Unassigned closure detail, list and export share one reach. */
	public function test_unassigned_closure_is_reachable_only_with_the_unassigned_key(): void {
		// Arrange.
		global $wpdb;
		$empty = $this->get( 'closures/export' )->get_raw_body();
		$store = new Closure_Store();
		$row = $store->create( $this->closure_fields( $this->closure_session(), 42 ) );
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'store_id' => null ), array( 'id' => $row['session_id'] ) );
		foreach ( array( true, false ) as $unassigned ) {
			$filter = static function () use ( $unassigned ) {
				return array( 'store_id' => array( 789 ) ) + ( $unassigned ? array( 'unassigned' => true ) : array() );
			};
			add_filter( Store_Reach::FILTER, $filter );
			try {
				// Act.
				$response = $this->get( 'closures/' . $row['id'] );
				$list = $this->get( 'closures' );
				$export = $this->get( 'closures/export' );
				$csv = $this->export_csv( $export );
				// Assert.
				$this->assertSame( $unassigned ? 200 : 404, $response->get_status() );
				$this->assertSame( 200, $list->get_status() );
				$this->assertSame( $unassigned ? array( $row['id'] ) : array(), array_column( $list->get_data(), 'id' ) );
				if ( $unassigned ) {
					$this->assertSame( 2, count( $csv ) );
					$this->assertSame( '42', $csv[1][ array_search( 'closure_number', $csv[0], true ) ] );
				} else {
					$absent = $this->get( 'closures/' . wp_generate_uuid4() );
					$this->assertSame( $absent->get_status(), $response->get_status() );
					$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
					$this->assertSame( $empty, $export->get_raw_body() );
				}
			} finally {
				remove_filter( Store_Reach::FILTER, $filter );
			}
		}
	}
}
