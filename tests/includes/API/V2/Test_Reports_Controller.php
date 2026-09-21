<?php
/**
 * Report REST contract tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Reports_Registry;
use WCPOS\WooCommercePOS\Services\Report_Document_Validator;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Gates must prevent queries, not just suppress their response. */
class Test_Reports_Controller extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture {
		tearDown as private tear_down_closures;
	}

	/**
	 * Actual callback argument.
	 *
	 * @var array|null
	 */
	private $received;
	/**
	 * Optional failing producer.
	 *
	 * @var callable|null
	 */
	private $producer;
	/**
	 * User observed inside the callback.
	 *
	 * @var int
	 */
	private $callback_user;
	/**
	 * Default valid query.
	 *
	 * @var array
	 */
	private $args;

	/** The route schema is declaration-independent; register after the hook snapshot. */
	public function setUp(): void {
		parent::setUp();
		$this->reset_registry();
		$this->received = null;
		$this->producer = null;
		add_filter(
			'woocommerce_pos_reports',
			function ( $reports ) {
				$reports['example'] = array(
					'title' => 'Example report',
					'scopes' => array( 'range', 'session' ),
					'capability' => 'read_example_report',
					'group_by' => array(
						array(
							'key' => 'cashier',
							'label' => 'Cashier',
						),
					),
					'extras' => array(
						'example' => array(
							'fields' => array(
								'note' => array(
									'type' => 'string',
									'label' => 'Note',
								),
							),
						),
					),
					'callback' => function ( $scope ) {
						$this->received = $scope;
						$this->callback_user = get_current_user_id();
						if ( $this->producer ) {
							return ( $this->producer )( $scope );
						}
						$data = ( new Receipt_Preview_Fixture_Loader() )->build( 'report' );
						$data['example'] = array( 'note' => 'Extra' );
						// Attempts to restate identity and scope must lose to the plugin.
						$data['store'] = array( 'id' => 999 );
						$data['register'] = array( 'id' => 'fake' );
						$data['cashier'] = array(
							'id' => 999,
							'name' => 'Forged',
						);
						$data['software'] = array( 'name' => 'Forged' );
						$data['fiscal'] = array( 'document_type' => 'sale' );
						return $data;
					},
				);
				return $reports;
			}
		);
		wp_get_current_user()->add_cap( 'access_woocommerce_pos' );
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports' );
		wp_get_current_user()->add_cap( 'read_example_report' );
		update_option( 'timezone_string', 'America/New_York' );
		$session = $this->closure_session();
		$today = ( new \DateTimeImmutable( 'today', new \DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );
		$this->args = array(
			'mode' => 'range',
			'from' => $today,
			'to' => $today,
			'register_id' => $session['register_id'],
		);
	}

	/** Clear the request cache as well as committed fixture rows. */
	public function tearDown(): void {
		$this->reset_registry();
		$this->tear_down_closures();
	}

	/** Simulate a fresh request without a test-only production method. */
	private function reset_registry(): void {
		$property = new \ReflectionProperty( Reports_Registry::class, 'reports' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/** Declared capability controls catalogue visibility and callables never leak into JSON. */
	public function test_registry_authorized_reports_appear_with_public_fields_only(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/reports' ) );
		$this->assertSame( 200, $response->get_status() );
		$reports = $response->get_data()['reports'];
		$this->assertSame( array( 'sales', 'cash_movements', 'example' ), array_column( $reports, 'key' ) );
		$this->assertSame( array( 'device', 'device', 'server' ), array_column( $reports, 'source' ) );
		$this->assertSame( array( 'key', 'title', 'scopes', 'group_by', 'source', 'tile', 'template' ), array_keys( $reports[2] ) );
		$this->assertSame( 'Example report', Receipt_Data_Schema::get_field_tree( 'report' )['example']['label'] );
		wp_get_current_user()->add_cap( 'read_example_report', false );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/reports' ) );
		$this->assertSame( array( 'sales', 'cash_movements' ), array_column( $response->get_data()['reports'], 'key' ) );
	}

	/** Returning raw request params or accepting callback-owned identity breaks this contract. */
	public function test_report_range_returns_valid_envelope_and_resolved_callback_scope(): void {
		remove_theme_mod( 'custom_logo' );
		$filtered = null;
		add_filter(
			'woocommerce_pos_report_data',
			static function ( $data, $key, $scope ) use ( &$filtered ) {
				$filtered = array( $key, $scope );
				$data['example']['note'] = 'Filtered';
				// The plugin's own statement of the document is not the filter's to rewrite.
				$data['report']['key'] = 'forged';
				$data['report']['title'] = 'Forged';
				$data['report']['scope']['register_name'] = 'Forged register';
				$data['register']['name'] = 'Forged register';
				$data['cashier']['id'] = 999;
				$data['software']['name'] = 'Forged';
				$data['fiscal']['document_type'] = 'receipt';
				return $data;
			},
			10,
			3
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( $this->args + array( 'group_by' => 'cashier' ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( Report_Document_Validator::validate( $data ) );
		$this->assertIsArray( $this->received );
		$this->assertNotInstanceOf( \WP_REST_Request::class, $this->received );
		$this->assertSame( 456, $this->received['store_id'] );
		$this->assertSame( get_current_user_id(), $data['cashier']['id'] );
		$this->assertSame( 'WCPOS', $data['software']['name'] );
		$this->assertNotSame( 999, $data['store']['id'] );
		$this->assertSame( '', $data['store']['logo'] );
		$this->assertSame( get_current_user_id(), $this->callback_user );
		$this->assertSame( 'cashier', $this->received['group_by'] );
		$this->assertSame( 'America/New_York', $this->received['timezone'] );
		$this->assertSame( 'example', $data['report']['key'] );
		$this->assertSame( 'Example report', $data['report']['title'] );
		$this->assertSame( $this->args['register_id'], $data['report']['scope']['register_id'] );
		$this->assertSame( $this->args['register_id'], $data['register']['id'] );
		$this->assertSame( $this->args['from'], $data['report']['scope']['from']['date_ymd'] );
		$this->assertSame( 'report', $data['fiscal']['document_type'] );
		$this->assertTrue( $data['fiscal']['is_report_document'] );
		$this->assertSame( array( 'example', $this->received ), $filtered );
		$this->assertSame( 'Filtered', $data['example']['note'] );
		// woocommerce_pos_report_data may enrich, but the identity is restored after it, exactly
		// as the closure path restores its own. Without this the filter could relabel a report,
		// or restate the scope it was authorised for, and still pass schema validation.
		$this->assertSame( 'Closure fixture', $data['report']['scope']['register_name'] );
		$this->assertSame( 'Closure fixture', $data['register']['name'] );
		$this->assertSame( 'report', $data['fiscal']['document_type'] );
		$this->assertTrue( $data['fiscal']['is_report_document'] );
		// The producer is told which stores the caller may reach, so it can honour a Pro scope it
		// would otherwise be unable to see; null means unrestricted.
		$this->assertArrayHasKey( 'allowed_store_ids', $this->received );
		$this->assertNull( $this->received['allowed_store_ids'] );
		$this->assertSame( 'Closure fixture', $this->received['register_name'] );
		$this->assertArrayNotHasKey( 'session', $this->received );
		$this->assertArrayNotHasKey( 'closure', $this->received );
		$this->assertSame( $data['report'], Receipt_Data_Schema::format_money_fields( $data )['report'] );
		$start = new \DateTimeImmutable( $this->received['from_utc'], new \DateTimeZone( 'UTC' ) );
		$end = new \DateTimeImmutable( $this->received['to_utc'], new \DateTimeZone( 'UTC' ) );
		$this->assertSame( $this->args['from'] . ' 00:00:00', $start->setTimezone( new \DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '00:00:00', $end->setTimezone( new \DateTimeZone( 'America/New_York' ) )->format( 'H:i:s' ) );
		$this->assertSame( $this->args['to'], $end->setTimezone( new \DateTimeZone( 'America/New_York' ) )->modify( '-1 day' )->format( 'Y-m-d' ) );
	}

	/** A closed session has a real number and date-field objects in its document. */
	public function test_report_session_returns_valid_document_from_stored_session(): void {
		global $wpdb;
		$session = $this->closure_session();
		( new Closure_Store() )->create( $this->closure_fields( $session, 42 ) );
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => $this->args['from'] ), array( 'id' => $session['id'] ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params(
			array(
				'mode' => 'session',
				'session_id' => $session['id'],
				'register_id' => $session['register_id'],
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( Report_Document_Validator::validate( $data ) );
		$this->assertSame( 42, $this->received['session_number'] );
		$this->assertSame( $this->args['from'], $this->received['business_day'] );
		$this->assertSame( '2026-09-11 12:00:00', $this->received['closed_at'] );
		$this->assertArrayHasKey( 'date_ymd', $data['report']['scope']['closed_at'] );
	}

	/**
	 * A denied request must not run the producer, even if its declared capability is held.
	 *
	 * Which layer refuses depends on the capability. `access_woocommerce_pos` is the whole
	 * plugin's floor and is enforced by the baseline gate in API::rest_pre_dispatch(), which
	 * runs before this controller is reached and answers with its own code; the two report
	 * capabilities are the controller's own. The part that matters either way is that the
	 * producer never runs.
	 */
	public function test_report_capability_and_access_refusals_never_run_callback(): void {
		foreach ( array(
			// The floor is the visible boundary: you cannot open Reports at all, and are told so.
			'view_woocommerce_pos_reports' => array( 403, 'wcpos_report_forbidden' ),
			// access_woocommerce_pos is the whole plugin's floor, enforced by the baseline gate in
			// API::rest_pre_dispatch() before this controller is reached, with its own code.
			'access_woocommerce_pos' => array( 403, 'woocommerce_pos_rest_forbidden' ),
			// A report's own capability is a visibility boundary: the report is hidden, not refused.
			'read_example_report' => array( 404, 'wcpos_report_not_found' ),
		) as $cap => $expected ) {
			wp_get_current_user()->add_cap( $cap, false );
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $this->args );
			$response = $this->server->dispatch( $request );
			$this->assertSame( $expected[0], $response->get_status() );
			$this->assertSame( $expected[1], $response->get_data()['code'] );
			$this->assertNull( $this->received );
			wp_get_current_user()->add_cap( $cap );
		}
	}

	/**
	 * A report the caller may not see is indistinguishable from one that does not exist.
	 *
	 * The catalogue already omits it. If the document route answered 403, or named it as
	 * device-computed, or validated its parameters first, it would hand back the registration the
	 * catalogue withheld — including which parameters it accepts.
	 */
	public function test_report_without_declared_capability_is_indistinguishable_from_missing(): void {
		wp_get_current_user()->add_cap( 'read_example_report', false );
		$missing = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/reports/no_such_report' ) );
		foreach ( array( $this->args, array( 'mode' => 'nonsense' ), array() ) as $query ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $query );
			$response = $this->server->dispatch( $request );
			$this->assertSame( $missing->get_status(), $response->get_status() );
			$this->assertSame( $missing->get_data()['code'], $response->get_data()['code'] );
			$this->assertNull( $this->received );
		}
	}

	/** Neither missing register, yesterday, nor an out-of-reach range may execute a Free query. */
	public function test_report_free_scope_refusals_never_run_callback(): void {
		$today = new \DateTimeImmutable( $this->args['from'] );
		foreach ( array( 1, 93, 0 ) as $days ) {
			$args = $this->args;
			$args['from'] = $today->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
			if ( 0 === $days ) {
				unset( $args['register_id'] );
			}
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $args );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'wcpos_report_scope_locked', $response->get_data()['code'] );
			$this->assertNull( $this->received );
		}
	}

	/**
	 * A store the caller cannot reach is absent, by either door.
	 *
	 * Pro answers `woocommerce_pos_closures_list_args` with the caller's stores; the fixture's
	 * store is 456, so a caller scoped to 789 must not reach it by naming its register (range)
	 * or its session (session mode). 404 rather than 403, so the scope cannot be probed.
	 */
	public function test_report_store_outside_the_caller_scope_is_not_found_and_never_runs_callback(): void {
		$session = $this->closure_session();
		( new Closure_Store() )->create( $this->closure_fields( $session, 7 ) );
		$absent = wp_generate_uuid4();
		$queries = array(
			'register' => array_replace( $this->args, array( 'register_id' => $session['register_id'] ) ),
			'session' => array(
				'mode' => 'session',
				'session_id' => $session['id'],
				'register_id' => $session['register_id'],
			),
		);
		// What a caller who simply named something that does not exist would be told.
		$missing = array(
			'register' => array_replace( $this->args, array( 'register_id' => $absent ) ),
			'session' => array(
				'mode' => 'session',
				'session_id' => $absent,
				'register_id' => $absent,
			),
		);
		$codes = array();
		foreach ( $missing as $door => $query ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $query );
			$codes[ $door ] = $this->server->dispatch( $request )->get_data()['code'];
		}
		add_filter(
			'woocommerce_pos_closures_list_args',
			static function ( $args ) {
				$args['store_id'] = array( 789 );
				return $args;
			}
		);
		foreach ( $queries as $door => $query ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $query );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 404, $response->get_status() );
			// Indistinguishable from absent: the refusal must not reveal that the row exists
			// in a store the caller cannot reach, nor (for a session) that it is still open.
			$this->assertSame( $codes[ $door ], $response->get_data()['code'] );
			$this->assertNull( $this->received );
		}
	}

	/**
	 * An unassigned row is not a free pass.
	 *
	 * `store_id` is `BIGINT NULL` on the sessions, registers and closures tables, so an
	 * unassigned row casts to 0. Exempting 0 as "no store was named" would let a scoped caller
	 * read every unassigned register and session. `Fiscal_Record_Store::resolve_document()`
	 * refuses the same row, and this read must not be laxer than the one beside it.
	 */
	public function test_report_register_with_no_store_is_refused_under_a_scope(): void {
		global $wpdb;
		$session = $this->closure_session();
		$wpdb->update( ( new Register_Store() )->table_name(), array( 'store_id' => null ), array( 'id' => $session['register_id'] ) );
		add_filter(
			'woocommerce_pos_closures_list_args',
			static function ( $args ) {
				$args['store_id'] = array( 456 );
				return $args;
			}
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( array_replace( $this->args, array( 'register_id' => $session['register_id'] ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_report_register_not_found', $response->get_data()['code'] );
		$this->assertNull( $this->received );
	}

	/**
	 * A malfunctioning scope filter must fail closed.
	 *
	 * The fallback for "the filter did not answer with an array" has to be deny, not unrestricted.
	 * An `is_array()` guard whose fallback means *no scope* looks like hardening while handing out
	 * every store at exactly the moment something is wrong.
	 */
	public function test_report_malformed_scope_filter_denies_rather_than_unscoping(): void {
		foreach ( array( 'nonsense', 7, null, false ) as $bad ) {
			add_filter(
				'woocommerce_pos_closures_list_args',
				static function () use ( $bad ) {
					return $bad;
				},
				20
			);
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $this->args );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 404, $response->get_status() );
			$this->assertNull( $this->received );
			remove_all_filters( 'woocommerce_pos_closures_list_args', 20 );
		}
	}

	/**
	 * A closed session keeps its own store even after its register moves.
	 *
	 * `Register_Store::update()` permits reassigning `store_id`. Scoping a session report by the
	 * register's *current* store would 404 a historical report for a caller plainly entitled to
	 * it, and would contradict the resolver's own use of the session's retained store.
	 */
	public function test_report_session_survives_its_register_moving_to_another_store(): void {
		global $wpdb;
		$session = $this->closure_session();
		( new Closure_Store() )->create( $this->closure_fields( $session, 11 ) );
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => $this->args['from'] ), array( 'id' => $session['id'] ) );
		// The register is reassigned to a store this caller cannot reach; the session is not.
		$wpdb->update( ( new Register_Store() )->table_name(), array( 'store_id' => 789 ), array( 'id' => $session['register_id'] ) );
		add_filter(
			'woocommerce_pos_closures_list_args',
			static function ( $args ) {
				$args['store_id'] = array( 456 );
				return $args;
			}
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params(
			array(
				'mode' => 'session',
				'session_id' => $session['id'],
				'register_id' => $session['register_id'],
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 11, $this->received['session_number'] );
		$this->assertSame( 456, $this->received['store_id'] );
	}

	/**
	 * A restriction we cannot read is one we must not guess at.
	 *
	 * `intval()` turns `false`, `null` or `'invalid'` into 0, and a row's NULL store casts to 0
	 * too — so an unreadable restriction would match exactly the unassigned rows it exists to
	 * protect. Validation has to happen before coercion, not after.
	 */
	public function test_report_unreadable_store_restriction_denies(): void {
		global $wpdb;
		$session = $this->closure_session();
		$wpdb->update( ( new Register_Store() )->table_name(), array( 'store_id' => null ), array( 'id' => $session['register_id'] ) );
		foreach ( array( 'invalid', false, null, 0, -1 ) as $bad ) {
			add_filter(
				'woocommerce_pos_closures_list_args',
				static function ( $args ) use ( $bad ) {
					$args['store_id'] = array( $bad );
					return $args;
				},
				20
			);
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( array_replace( $this->args, array( 'register_id' => $session['register_id'] ) ) );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 404, $response->get_status() );
			$this->assertNull( $this->received );
			remove_all_filters( 'woocommerce_pos_closures_list_args', 20 );
		}
	}

	/**
	 * Authorising a historical session does not authorise its register's current details.
	 *
	 * Once the register moves to a store the caller cannot reach, its live name is out-of-scope
	 * metadata — and a later rename would otherwise leak through every replay of the old session.
	 */
	public function test_report_session_does_not_leak_a_moved_registers_current_name(): void {
		global $wpdb;
		$session = $this->closure_session();
		( new Closure_Store() )->create( $this->closure_fields( $session, 12 ) );
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => $this->args['from'] ), array( 'id' => $session['id'] ) );
		$wpdb->update(
			( new Register_Store() )->table_name(),
			array(
				'store_id' => 789,
				'name' => 'Renamed in another store',
			),
			array( 'id' => $session['register_id'] )
		);
		add_filter(
			'woocommerce_pos_closures_list_args',
			static function ( $args ) {
				$args['store_id'] = array( 456 );
				return $args;
			}
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params(
			array(
				'mode' => 'session',
				'session_id' => $session['id'],
				'register_id' => $session['register_id'],
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( 'Renamed in another store', $this->received['register_name'] );
		$this->assertNotSame( 'Renamed in another store', $response->get_data()['report']['scope']['register_name'] );
	}

	/** A caller allowed no stores at all reads no report. */
	public function test_report_deny_all_store_scope_refuses_every_report(): void {
		add_filter(
			'woocommerce_pos_closures_list_args',
			static function ( $args ) {
				$args['store_id'] = array();
				return $args;
			}
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( $this->args );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertNull( $this->received );
	}

	/** Session identity must not bypass the Free day or explicit-register boundary. */
	public function test_report_session_scope_refusals_never_run_callback(): void {
		global $wpdb;
		$session = $this->closure_session();
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => '2000-01-01' ), array( 'id' => $session['id'] ) );
		$args = array(
			'mode' => 'session',
			'session_id' => $session['id'],
			'register_id' => $session['register_id'],
		);
		foreach ( array( $args, array_diff_key( $args, array( 'register_id' => true ) ) ) as $query ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $query );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'wcpos_report_scope_locked', $response->get_data()['code'] );
			$this->assertNull( $this->received );
		}
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => $this->args['from'] ), array( 'id' => $session['id'] ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( array_diff_key( $args, array( 'register_id' => true ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpos_report_scope_locked', $response->get_data()['code'] );
		$this->assertNull( $this->received );
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( array_replace( $args, array( 'register_id' => $this->args['register_id'] ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_report_scope_mismatch', $response->get_data()['code'] );
		$this->assertNull( $this->received );
	}

	/** Dates and identity types are validated before any query callable. */
	public function test_report_invalid_parameters_are_rejected_before_callback(): void {
		foreach ( array(
			array( 'mode' => 'other' ),
			array( 'from' => '2026-02-30' ),
			array( 'group_by' => 'unknown' ),
			array( 'register_id' => 12 ),
			array( 'store_id' => array( 1 ) ),
			array( 'session_id' => 2 ),
			array( 'mode' => 'session' ),
			array( 'to' => '2000-01-01' ),
		) as $invalid ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( array_replace( $this->args, $invalid ) );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 400, $response->get_status() );
			$this->assertNull( $this->received );
		}
	}

	/**
	 * Parameter errors are for callers who may see the report; the unauthorized get nothing.
	 *
	 * This deliberately runs the other way round from an earlier version of this test. A
	 * parameter-specific 400 for a caller lacking the capability would confirm the report exists
	 * and reveal which scopes it accepts, which is exactly what the catalogue withholds. A caller
	 * who may see it still gets the useful error.
	 */
	public function test_report_parameter_errors_are_withheld_from_an_unauthorized_caller(): void {
		$bad = array_replace( $this->args, array( 'mode' => 'other' ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( $bad );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_report_scope_unsupported', $response->get_data()['code'] );

		wp_get_current_user()->add_cap( 'read_example_report', false );
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( $bad );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_report_not_found', $response->get_data()['code'] );
		$this->assertNull( $this->received );
	}

	/** A broken registration must not take down the catalogue route. */
	public function test_registry_malformed_registration_still_serves_valid_reports(): void {
		add_filter(
			'woocommerce_pos_reports',
			static function ( $reports ) {
				$reports['broken'] = array(
					'title' => 'Broken',
					'scopes' => array( 'range' ),
					'callback' => false,
				);
				return $reports;
			}
		);
		$this->reset_registry();
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/reports' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'sales', 'cash_movements', 'example' ), array_column( $response->get_data()['reports'], 'key' ) );
	}

	/** Blind cashiers may not obtain catalogue metadata either. */
	public function test_registry_without_reports_capability_is_forbidden(): void {
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports', false );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/reports' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpos_report_forbidden', $response->get_data()['code'] );
	}

	/** Device declarations cannot accidentally become server aggregations. */
	public function test_report_device_and_unknown_keys_return_distinct_not_found_errors(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/reports/sales' ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_report_is_device_computed', $response->get_data()['code'] );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/reports/missing' ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_report_not_found', $response->get_data()['code'] );
	}

	/** Plugin errors produce a named failure, not an empty document or an uncaught exception. */
	public function test_report_invalid_and_throwing_producers_return_named_failures(): void {
		foreach ( array(
			static function () {
				return 'invalid'; },
			static function () {
				return array( 'report' => array() ); },
			static function () {
				throw new \RuntimeException( 'Plugin exploded' ); },
		) as $producer ) {
			$this->producer = $producer;
			$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
			$request->set_query_params( $this->args );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( 'wcpos_report_failed', $response->get_data()['code'] );
			$this->assertSame( 'example', $response->get_data()['data']['key'] );
			$this->assertStringContainsString( 'example', $response->get_data()['message'] );
		}
		$this->assertStringContainsString( 'Plugin exploded', $response->get_data()['message'] );
		$this->assertArrayNotHasKey( 'trace', $response->get_data()['data'] );
	}

	/** Validation must run after extensions, not before them. */
	public function test_report_filter_invalid_document_is_rejected(): void {
		add_filter(
			'woocommerce_pos_report_data',
			static function ( $data ) {
				$data['report']['column_count'] = 999;
				return $data;
			}
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/reports/example' );
		$request->set_query_params( $this->args );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'example', $response->get_data()['data']['key'] );
	}

	/** Existing id-0 guards keep working; extensions cannot restate closure fiscal identity. */
	public function test_receipt_filter_closure_and_xreport_receive_unsaved_order_and_preserve_identity(): void {
		$seen = array();
		add_filter(
			'woocommerce_pos_receipt_data',
			static function ( $data, $order, $mode ) use ( &$seen ) {
				$seen[] = array( $order->get_id(), $mode );
				$data['extra_note'] = 'Extension';
				$data['fiscal']['receipt_number'] = 'forged';
				$data['fiscal']['document_type'] = 'sale';
				// What the shipped templates actually head the document with.
				$data['closure']['number'] = 999;
				$data['fiscal']['is_x_report'] = ! ( $data['fiscal']['is_x_report'] ?? false );
				// Not part of the identity: a fiscal-jurisdiction extension's own enrichment.
				$data['fiscal']['extra_fields'] = array( 'jurisdiction' => 'AT' );
				// The frozen financial record. A short drawer must not be printable as balanced.
				$data['closure']['counted'] = array( 'cash' => '999.0000' );
				$data['closure']['period_sales_total'] = '999.0000';
				return $data;
			},
			10,
			3
		);
		$session = $this->closure_session();
		$builder = new Receipt_Data_Builder();
		$xreport = $builder->build_closure_document( $session, true );
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session, 42 ) );
		$seen = array();
		$data = $builder->build_closure_document( $closure );
		$xreport = $builder->build_closure_document( $this->closure_session(), true );
		$this->assertSame( array( array( 0, 'closure' ), array( 0, 'xreport' ) ), $seen );
		$this->assertSame( '42', $data['fiscal']['receipt_number'] );
		$this->assertSame( 'closure', $data['fiscal']['document_type'] );
		$this->assertSame( '', $xreport['fiscal']['receipt_number'] );
		$this->assertSame( 'xreport', $xreport['fiscal']['document_type'] );
		$this->assertSame( 'Extension', $data['extra_note'] );
		// Only the named identity is restored after the filter, as on the refund path: an
		// extension keeps its other fiscal additions. Restoring the whole block instead would
		// make this filter read-only for fiscal, and nothing else here would notice.
		$this->assertSame( array( 'jurisdiction' => 'AT' ), $data['fiscal']['extra_fields'] );
		// What a template prints is protected, not merely what fiscal records: both shipped
		// closure templates head the document with closure.number and branch on
		// fiscal.is_x_report, so an extension must not be able to print a closure under another
		// number, or print an X-report as a numbered closure.
		$this->assertSame( 42, $data['closure']['number'] );
		// The whole frozen record is restored, not just the printed identity: the shipped
		// templates print these figures under the authentic closure number, so an extension able
		// to rewrite them could make a short drawer print as balanced.
		$this->assertSame( array( 'cash' => '101.0000' ), $data['closure']['counted'] );
		$this->assertNotSame( '999.0000', $data['closure']['period_sales_total'] );
		$this->assertFalse( $data['fiscal']['is_x_report'] );
		$this->assertTrue( $xreport['fiscal']['is_x_report'] );
		$this->assertTrue( $data['fiscal']['is_closure_document'] );
	}
}
