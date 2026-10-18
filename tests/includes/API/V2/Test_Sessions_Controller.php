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
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Store_Reach;
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
	 * @param array  $query Query parameters.
	 */
	private function post( string $route, array $fields, array $query = array() ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/' . $route );
		$request->set_body_params( $fields );
		$request->set_query_params( $query );
		return $this->server->dispatch( $request );
	}

	/** A valid client opening.
	 *
	 * @param int|null $store_id Register's store, or null when unassigned.
	 */
	private function fields( ?int $store_id = null ): array {
		$register_id = ( new Register_Store() )->create(
			array(
				'name' => 'Front',
				'store_id' => $store_id,
			)
		)['id'];
		$this->register_ids[] = $register_id;
		return array(
			'id' => wp_generate_uuid4(),
			'register_id' => $register_id,
			'opened_at' => '2026-09-11T10:00:00+02:00',
			'expected_float' => '95',
			'counted_float' => '100',
		);
	}

	/** The register owns both the returned and persisted session store. */
	public function test_session_opening_takes_the_store_from_its_register(): void {
		// Arrange.
		$fields = $this->fields( 456 );
		$fields['store_id'] = 999;

		// Act.
		$response = $this->post( 'sessions', $fields );

		// Assert.
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 456, $data['store_id'] );
		$stored = ( new Register_Session_Store() )->get( $data['id'] );
		$this->assertSame( 456, $stored['store_id'] );
	}

	/** The client preamble's query store cannot override the register's store. */
	public function test_session_opening_ignores_the_query_store(): void {
		// Arrange.
		$fields = $this->fields( 456 );
		$query = array(
			'store_id' => 999,
		);

		// Act.
		$response = $this->post( 'sessions', $fields, $query );

		// Assert.
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 456, $response->get_data()['store_id'] );
	}

	/** An unassigned register keeps its session unassigned despite the body store. */
	public function test_session_opening_on_an_unassigned_register_stores_null(): void {
		// Arrange.
		$fields = $this->fields();
		$fields['store_id'] = 999;

		// Act.
		$response = $this->post( 'sessions', $fields );

		// Assert.
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( null, $response->get_data()['store_id'] );
	}

	/** Unreachable registers answer as unknown without persisting an opening. */
	public function test_session_opening_on_a_register_out_of_reach_is_refused_as_unknown(): void {
		// Arrange.
		$fields = $this->fields( 456 );
		$unknown = $fields;
		$unknown['register_id'] = wp_generate_uuid4();
		$allowed = $this->fields( 789 );
		$filter = static function () {
			return array(
				'store_id' => array( 789 ),
			);
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			// Act.
			$absent = $this->post( 'sessions', $unknown );
			$response = $this->post( 'sessions', $fields );

			// Assert.
			$this->assertSame( 400, $absent->get_status() );
			$this->assertSame( 'rest_invalid_param', $absent->get_data()['code'] );
			$this->assertSame( $absent->get_status(), $response->get_status() );
			$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
			$this->assertSame( null, ( new Register_Session_Store() )->get( $fields['id'] ) );
			$this->assertSame( 201, $this->post( 'sessions', $allowed )->get_status() );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}

	/** Restricted reach must explicitly admit an unassigned register. */
	public function test_session_opening_on_an_unassigned_register_follows_the_unassigned_reach(): void {
		// Arrange.
		$fields = $this->fields();
		$unknown = $fields;
		$unknown['register_id'] = wp_generate_uuid4();
		foreach ( array( false, true ) as $unassigned ) {
			$filter = static function () use ( $unassigned ) {
				$reach = array(
					'store_id' => array( 789 ),
				);
				if ( $unassigned ) {
					$reach['unassigned'] = true;
				}
				return $reach;
			};
			add_filter( Store_Reach::FILTER, $filter );
			try {
				// Act.
				$absent = $this->post( 'sessions', $unknown );
				$response = $this->post( 'sessions', $fields );

				// Assert.
				$this->assertSame( 400, $absent->get_status() );
				$this->assertSame( 'rest_invalid_param', $absent->get_data()['code'] );
				if ( $unassigned ) {
					$this->assertSame( 201, $response->get_status() );
					$this->assertSame( null, $response->get_data()['store_id'] );
				} else {
					$this->assertSame( $absent->get_status(), $response->get_status() );
					$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
					$this->assertSame( null, ( new Register_Session_Store() )->get( $fields['id'] ) );
				}
			} finally {
				remove_filter( Store_Reach::FILTER, $filter );
			}
		}
	}

	/** Session detail shares its ledger, refund and movement reads across report figures. */
	public function test_session_detail_reads_ledger_and_movements_once(): void {
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports' );
		$id = $this->counting_session_with_cash_sale();
		$reads = array(
			'orders' => 0,
			'refunds' => 0,
			'movements' => 0,
		);
		$observe = static function ( $sql ) use ( &$reads ) {
			if ( false !== strpos( $sql, 'SELECT DISTINCT' ) && false !== strpos( $sql, Ledger::META_KEY ) ) {
				++$reads['orders'];
			}
			if ( false !== strpos( $sql, 'SELECT DISTINCT' ) && false !== strpos( $sql, 'shop_order_refund' ) ) {
				++$reads['refunds'];
			}
			if ( 0 === strpos( $sql, 'SELECT * FROM' ) && false !== strpos( $sql, 'wcpos_cash_movements' ) ) {
				++$reads['movements'];
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $id ) );
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'cash' => '100.0000' ), $response->get_data()['expected'] );
		$this->assertSame(
			array(
				'orders' => 1,
				'refunds' => 1,
				'movements' => 1,
			),
			$reads
		);
	}

	/** Snapshot membership includes split tenders and remains available when counting blind. */
	public function test_session_detail_returns_counted_ids_including_when_blind(): void {
		// Arrange.
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$order_ids = array();
		$ledger_row_ids = array();
		$ledger_rows = array();
		foreach ( array( array( 'pos_cash', 'pos_card' ), array( 'pos_cash' ) ) as $methods ) {
			$order = new \WC_Order();
			$order->save();
			$order_ids[] = $order->get_id();
			$rows = array();
			foreach ( $methods as $method ) {
				$row_id = wp_generate_uuid4();
				$ledger_row_ids[] = $row_id;
				$rows[] = array(
					'id' => $row_id,
					'method_id' => $method,
					'kind' => 'pos_cash' === $method ? 'cash' : 'card',
					'amount' => '10',
					'status' => 'captured',
					'session_id' => $id,
				);
			}
			Ledger::instance()->save( $order, $rows, false );
			// Seed an older stored version without the ledger writer restamping it.
			$stored = Ledger::instance()->read( $order );
			foreach ( $stored as &$row ) {
				$row['updated_at_gmt'] = '2026-09-11 09:30:00';
				$ledger_rows[] = array(
					'id' => $row['id'],
					'updated_at_gmt' => '2026-09-11 09:30:00',
					'status' => 'captured',
				);
			}
			unset( $row );
			$order->update_meta_data(
				Ledger::META_KEY,
				wp_json_encode(
					array(
						'schema' => Ledger::SCHEMA,
						'payments' => $stored,
					)
				)
			);
			$order->save_meta_data();
		}
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order_ids[0] );
		$refund->set_amount( '2' );
		$refund->update_meta_data( '_wcpos_session', $id );
		$refund->save();
		// WooCommerce restamps date_modified on every save; the version reported must be the stored one.
		$refund_modified = gmdate( 'Y-m-d H:i:s', wc_get_order( $refund->get_id() )->get_date_modified( 'edit' )->getTimestamp() );
		$movement_id = wp_generate_uuid4();
		$movement = $this->post(
			'movements',
			array(
				'id' => $movement_id,
				'session_id' => $id,
				'type' => 'paid_in',
				'amount' => '5',
				'reason' => 'Change',
				'created_at' => '2026-09-11T10:00:00Z',
			)
		);
		$this->assertSame( 201, $movement->get_status() );
		$reader = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$reader->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $reader->ID );
		foreach ( array( false, true ) as $can_report ) {
			wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports', $can_report );
			// Act.
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $id ) );
			// Assert.
			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertEqualsCanonicalizing( $order_ids, $data['order_ids'] );
			$this->assertEqualsCanonicalizing( $ledger_row_ids, $data['ledger_row_ids'] );
			$this->assertSame( array( $refund->get_id() ), $data['refund_ids'] );
			foreach ( $data['ledger_rows'] as &$row ) {
				foreach ( array( 'amount', 'refunded_amount', 'method_id', 'kind' ) as $field ) {
					$this->assertArrayNotHasKey( $field, $row );
				}
				$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['version'] );
				unset( $row['version'] );
			}
			unset( $row );
			$this->assertEqualsCanonicalizing( $ledger_rows, $data['ledger_rows'] );
			$this->assertCount( 1, $data['refunds'] );
			$this->assertSame( $refund->get_id(), $data['refunds'][0]['id'] );
			$this->assertSame( $refund_modified, $data['refunds'][0]['updated_at_gmt'] );
			$this->assertNotEmpty( $data['refunds'][0]['allocation_version'] );
			$this->assertSame( array( $movement_id ), array_column( $data['movements'], 'id' ) );
			$this->assertSame( 2, $data['sales_count'] );
			if ( $can_report ) {
				$this->assertSame(
					array(
						'cash' => '123.0000',
						'card' => '10.0000',
					),
					$data['expected']
				);
			} else {
				$this->assertArrayNotHasKey( 'expected', $data );
			}
		}
	}

	/** Only this refund's tender split changes its allocation version, not unrelated ledger edits. */
	public function test_refund_allocation_version_ignores_unrelated_changes_but_tracks_settlement(): void {
		// Arrange.
		$session = $this->post( 'sessions', $this->fields() )->get_data();
		$order = new \WC_Order();
		$order->save();
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '5' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		$refund->save();
		$rows = array(
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_card',
				'kind' => 'card',
				'amount' => '5',
				'status' => 'captured',
				'session_id' => wp_generate_uuid4(),
				'updated_at_gmt' => '2026-09-11 09:30:00',
				'refunds' => array(
					array(
						'id' => $refund->get_id(),
						'amount' => '5',
						'status' => 'pending',
					),
				),
			),
		);
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports' );
		$before = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) );
		$this->assertSame( 200, $before->get_status() );
		$before = $before->get_data();
		$figures = Session_Figures::for_session( $session );
		$memoised = $figures->refund_versions();

		// Act. Add an unrelated payment and settle another refund on the same parent.
		$other_refund = new \WC_Order_Refund();
		$other_refund->set_parent_id( $order->get_id() );
		$other_refund->set_amount( '1' );
		$other_refund->save();
		$rows[] = array(
			'id' => wp_generate_uuid4(),
			'method_id' => 'pos_cash',
			'kind' => 'cash',
			'amount' => '10',
			'status' => 'captured',
			'session_id' => $rows[0]['session_id'],
		);
		$rows[0]['updated_at_gmt'] = '2026-09-11 09:31:00';
		$rows[0]['refunds'][] = array(
			'id' => $other_refund->get_id(),
			'amount' => '1',
			'status' => 'succeeded',
		);
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();
		$unrelated = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) );

		// Assert. Neither this refund's version nor its expected balance changed.
		$this->assertSame( 200, $unrelated->get_status() );
		$this->assertSame( $before['refunds'], $unrelated->get_data()['refunds'] );
		$this->assertSame( $before['expected'], $unrelated->get_data()['expected'] );

		// Act. Settle within the same clock second; neither timestamp changes.
		$rows[0]['refunds'][0]['status'] = 'succeeded';
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();
		$after = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) );

		// Assert. The old instance still attributes using the inputs it versioned.
		$this->assertSame( 200, $after->get_status() );
		$after = $after->get_data();
		$this->assertSame( array(), $after['ledger_rows'] );
		$this->assertSame( $before['refunds'][0]['updated_at_gmt'], $after['refunds'][0]['updated_at_gmt'] );
		$this->assertNotEmpty( $before['refunds'][0]['allocation_version'] );
		$this->assertNotEmpty( $after['refunds'][0]['allocation_version'] );
		$this->assertNotSame( $before['refunds'][0]['allocation_version'], $after['refunds'][0]['allocation_version'] );
		$this->assertSame( $memoised, $figures->refund_versions() );
		$this->assertSame( array( 'cash' => '95.0000' ), $figures->expected() );
		$this->assertSame( array( 'cash' => '95.0000' ), $before['expected'] );
		$this->assertSame(
			array(
				'cash' => '100.0000',
				'card' => '-5.0000',
			),
			$after['expected']
		);
	}

	/** Missing parents must not prevent blind counts, but reports must not silently lose refunds. */
	public function test_blind_detail_deleted_refund_parent_returns_membership(): void {
		// Arrange. WooCommerce refuses a refund whose parent is already gone and cascades a
		// parent delete onto its refunds, so orphan the refund at the storage level, under
		// both storages (one of the two deletes is a no-op).
		global $wpdb;
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$order = new \WC_Order();
		$parent_id = $order->save();
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $parent_id );
		$refund->set_amount( '5' );
		$refund->update_meta_data( '_wcpos_session', $id );
		$refund->save();
		$wpdb->delete( $wpdb->posts, array( 'ID' => $parent_id ) );
		$wpdb->delete( $wpdb->prefix . 'wc_orders', array( 'id' => $parent_id ) );
		clean_post_cache( $parent_id );
		wp_cache_flush();
		$this->assertFalse( wc_get_order( $parent_id ) );
		$reader = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$reader->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $reader->ID );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( $refund->get_id() ), $data['refund_ids'] );
		$this->assertSame( $refund->get_id(), $data['refunds'][0]['id'] );
		$this->assertNull( $data['refunds'][0]['allocation_version'] );
		$this->assertArrayNotHasKey( 'expected', $data );
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports' );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $id ) );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpos_session_write_failed', $response->get_data()['code'] );
	}

	/** The device's business day is optional, but supplied values must be date strings. */
	public function test_session_business_day_validates_and_preserves_stamp(): void {
		$fields = $this->fields();
		foreach ( array( '2026-9-11', 'yesterday', array(), 20260911, null, "2026-09-11\n", '2026-02-31', '0000-00-00', '2026-02-29' ) as $bad ) {
			$this->assertSame( 400, $this->post( 'sessions', $fields + array( 'business_day' => $bad ) )->get_status() );
		}
		$response = $this->post( 'sessions', $fields + array( 'business_day' => '2026-09-10' ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '2026-09-10', $response->get_data()['business_day'] );
		$this->assertNull( $this->post( 'sessions', $this->fields() )->get_data()['business_day'] );
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
		foreach ( array( 'sessions', 'sessions/' . $fields['id'] . '/status', 'sessions/' . $fields['id'] . '/approve', 'movements' ) as $route ) {
			$this->assertSame( 403, $this->post( $route, $fields )->get_status() );
		}
		foreach ( array( 'sessions', 'sessions/' . $fields['id'], 'sessions/' . $fields['id'] . '/movements' ) as $route ) {
			$this->assertSame( 200, $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/' . $route ) )->get_status() );
		}
	}

	/** The expected figure is a report: a cashier counting blind never receives it. */
	public function test_blind_cashier_session_read_omits_expected(): void {
		$fields = $this->fields();
		$this->post( 'sessions', $fields );
		$route = '/wcpos/v2/sessions/' . $fields['id'];
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports' );
		$sighted = $this->server->dispatch( $this->wp_rest_get_request( $route ) )->get_data();
		$this->assertArrayHasKey( 'expected', $sighted );
		$this->assertSame( Session_Figures::for_session( $sighted )->terms(), $sighted['terms'] );
		$this->assertSame( array(), $sighted['refund_ids'] );
		$this->assertSame( array(), $sighted['ledger_rows'] );
		$this->assertSame( array(), $sighted['refunds'] );
		$blind = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $blind )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $blind );
		$response = $this->server->dispatch( $this->wp_rest_get_request( $route ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'expected', $data );
		$this->assertArrayNotHasKey( 'terms', $data );
		$this->assertArrayHasKey( 'sales_count', $data );
		$this->assertSame( array(), $data['order_ids'] );
		$this->assertSame( array(), $data['ledger_row_ids'] );
		$this->assertSame( array(), $data['refund_ids'] );
		$this->assertSame( array(), $data['ledger_rows'] );
		$this->assertSame( array(), $data['refunds'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['as_of_gmt'] );
		$this->assertLessThanOrEqual( time() + 1, strtotime( $data['as_of_gmt'] . ' UTC' ) );
		$this->assertArrayHasKey( 'movements', $data );
	}

	/** Post one movement on a session and return its id.
	 *
	 * @param string      $session_id Session UUID.
	 * @param string      $type Movement type.
	 * @param string      $amount Amount.
	 * @param string      $reason Reason.
	 * @param string|null $voids Voided movement, for a void.
	 */
	private function movement( string $session_id, string $type, string $amount, string $reason, ?string $voids = null ): string {
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session_id,
			'type' => $type,
			'amount' => $amount,
			'reason' => $reason,
			'created_at' => '2026-09-11T10:00:00Z',
		);
		if ( null !== $voids ) {
			$fields['voids'] = $voids;
		}
		$this->assertSame( 201, $this->post( 'movements', $fields )->get_status() );
		return $fields['id'];
	}

	/** The drawer terms add up exactly to expected cash, from the snapshot expected() reads. */
	public function test_session_detail_terms_sum_to_expected_cash(): void {
		// Arrange. Movements first: the void's transaction commits the test transaction.
		$session = $this->post( 'sessions', $this->fields() )->get_data();
		$paid_in = $this->movement( $session['id'], 'paid_in', '20', 'Float top-up' );
		$this->movement( $session['id'], 'paid_out', '5', 'Bank run' );
		$voided = $this->movement( $session['id'], 'paid_in', '7', 'Mistake' );
		$this->movement( $session['id'], 'void', '0', '', $voided );
		$this->start_transaction();
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports' );
		$leg = static function ( array $fields ) use ( $session ) {
			return array_merge(
				array(
					'id' => wp_generate_uuid4(),
					'method_id' => 'pos_cash',
					'kind' => 'cash',
					'status' => 'captured',
					'session_id' => $session['id'],
				),
				$fields
			);
		};
		$first = new \WC_Order();
		$first->set_total( '1000' );
		$first->save();
		// A raw negative cash leg is a refund tender: expected() debits it from cash.
		$pending = $leg(
			array(
				'amount' => '999',
				'status' => 'pending',
			)
		);
		Ledger::instance()->save( $first, array( $leg( array( 'amount' => '50' ) ), $leg( array( 'amount' => '-4' ) ), $pending ), false );
		$second = new \WC_Order();
		$second->set_total( '1000' );
		$second->save();
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $second->get_id() );
		$refund->set_amount( '40' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		$refund->save();
		// 30 of the refund settles on the card; the 10 remainder is attributed to cash.
		$card = $leg(
			array(
				'method_id' => 'pos_card',
				'kind' => 'card',
				'amount' => '30',
				'refunds' => array(
					array(
						'id' => $refund->get_id(),
						'amount' => '30',
						'status' => 'succeeded',
					),
				),
			)
		);
		$cod = $leg(
			array(
				'method_id' => 'cod',
				'amount' => '25',
			)
		);
		Ledger::instance()->save( $second, array( $cod, $card ), false );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array(
				'cash' => '176.0000',
				'card' => '0.0000',
			),
			$data['expected']
		);
		$this->assertSame(
			array(
				'float' => '100.0000',
				'cash_sales' => array(
					'amount' => '75.0000',
					'count' => 2,
				),
				'paid_in' => array(
					array(
						'id' => $paid_in,
						'amount' => '20.0000',
						'reason' => 'Float top-up',
					),
				),
				'paid_out' => array(
					'amount' => '5.0000',
					'count' => 1,
					'note' => 'Bank run',
				),
				'cash_refunds' => array(
					'amount' => '14.0000',
					'count' => 2,
				),
				'no_sales' => 0,
				'voids' => 1,
			),
			$data['terms']
		);
		$terms = $data['terms'];
		$this->assertSame(
			$data['expected']['cash'],
			Closure_Store::sum(
				array_merge(
					array( $terms['float'], $terms['cash_sales']['amount'] ),
					array_column( $terms['paid_in'], 'amount' ),
					array( '-' . $terms['paid_out']['amount'], '-' . $terms['cash_refunds']['amount'] )
				)
			)
		);
		$this->assertSame( Session_Figures::for_session( $session )->terms(), $terms );
	}

	/** A no-sale counts until voided; every void marker counts. */
	public function test_session_terms_count_no_sales_and_voids(): void {
		$session = $this->post( 'sessions', $this->fields() )->get_data();
		$this->movement( $session['id'], 'no_sale', '0', 'Change for the float' );
		$voided = $this->movement( $session['id'], 'no_sale', '0', 'Opened by mistake' );
		$this->movement( $session['id'], 'void', '0', '', $voided );
		$paid_out = $this->movement( $session['id'], 'paid_out', '3', 'Milk' );
		$this->movement( $session['id'], 'void', '0', '', $paid_out );
		$this->start_transaction();
		wp_get_current_user()->add_cap( 'view_woocommerce_pos_reports' );

		$terms = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) )->get_data()['terms'];

		$this->assertSame( 1, $terms['no_sales'] );
		$this->assertSame( 2, $terms['voids'] );
		$this->assertSame(
			array(
				'amount' => '0.0000',
				'count' => 0,
				'note' => null,
			),
			$terms['paid_out']
		);
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
		) as $key => $value ) {
			$this->assertSame( 400, $this->post( 'sessions', array_merge( $fields, array( $key => $value ) ) )->get_status(), $key );
		}
		$fields['expected_float'] = null;
		$this->assertNull( $this->post( 'sessions', $fields )->get_data()['opening_variance'] );
	}

	/** Name the offending field, so the till can say which one and the log can record it.
	 *
	 * Without this the client gets a bare 400 with a status and nothing else, and a refused
	 * cash movement — money that has physically moved — leaves the cashier no way to tell
	 * whether the amount, the reason or the timestamp was wrong.
	 */
	public function test_invalid_param_names_the_offending_field(): void {
		$opening = $this->fields();
		foreach ( array(
			'register_id' => 'bad',
			'opened_at' => '2026-02-30T10:00:00Z',
			'counted_float' => '-1',
			'expected_float' => '1e2',
		) as $key => $value ) {
			$response = $this->post( 'sessions', array_merge( $opening, array( $key => $value ) ) );
			$this->assertSame( 400, $response->get_status(), $key );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], $key );
			$this->assertArrayHasKey( $key, $response->get_data()['data']['params'], $key );
		}

		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$movement = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $id,
			'type' => 'paid_in',
			'amount' => '20',
			'reason' => 'Change',
			'created_at' => '2026-09-11T10:00:00Z',
		);
		foreach ( array(
			// Every shape the POS used to let through: Number('10.') and Number('.5') are both
			// positive, so the confirm button was happy and the server was not.
			'amount' => array( '10.', '.5', '1e2', ' 10', '10,50', '0', '-5' ),
			'reason' => array( '', '   ', str_repeat( 'x', 501 ) ),
			'created_at' => array( 'bad' ),
			'type' => array( 'refund' ),
			'session_id' => array( 'bad' ),
		) as $key => $values ) {
			foreach ( $values as $value ) {
				$response = $this->post(
					'movements',
					array_merge(
						$movement,
						array(
							$key => $value,
							'id' => wp_generate_uuid4(),
						)
					)
				);
				$this->assertSame( 400, $response->get_status(), $key . '=' . var_export( $value, true ) );
				$this->assertArrayHasKey( $key, $response->get_data()['data']['params'], $key . '=' . var_export( $value, true ) );
			}
		}

		// A no sale carries no amount but still needs a reason, and used to 400 every single time.
		$response = $this->post(
			'movements',
			array_merge(
				$movement,
				array(
					'id' => wp_generate_uuid4(),
					'type' => 'no_sale',
					'amount' => '0',
					'reason' => '',
				)
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'reason', $response->get_data()['data']['params'] );

		$this->post(
			'sessions/' . $id . '/status',
			array(
				'status' => 'counting',
				'at' => '2026-09-11T11:00:00Z',
			)
		);
		foreach ( array(
			'at' => 'bad',
			'counted' => array( 'cash' => '10.' ),
		) as $key => $value ) {
			$response = $this->post(
				'sessions/' . $id . '/status',
				array_merge(
					array(
						'status' => 'closed',
						'at' => '2026-09-11T12:00:00Z',
						'counted' => array( 'cash' => '100' ),
					),
					array( $key => $value )
				)
			);
			$this->assertSame( 400, $response->get_status(), $key );
			$this->assertArrayHasKey( $key, $response->get_data()['data']['params'], $key );
		}

		// A malformed replay id never reaches a field validator, so it names `id` itself.
		$bad_id = $this->post( 'movements', array_merge( $movement, array( 'id' => 'bad' ) ) );
		$this->assertSame( 400, $bad_id->get_status() );
		$this->assertArrayHasKey( 'id', $bad_id->get_data()['data']['params'] );

		// Collection reads name their offending query parameter too.
		foreach ( array(
			'status' => 'bad',
			'page' => '0',
			'per_page' => '101',
			'register_id' => 'bad',
		) as $key => $value ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/sessions' );
			$request->set_param( $key, $value );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 400, $response->get_status(), $key );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], $key );
			$this->assertArrayHasKey( $key, $response->get_data()['data']['params'], $key );
		}
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

	/** Status closes must accept only counts that a later closure can store.
	 *
	 * @dataProvider invalid_closing_counts
	 * @param string $method Tender name.
	 * @param string $amount Tender amount.
	 */
	public function test_status_close_rejects_invalid_tenders( string $method, string $amount ): void {
		$id = $this->counting_session_with_cash_sale();
		$response = $this->post(
			'sessions/' . $id . '/status',
			array(
				'status' => 'closed',
				'at' => '2026-09-11T12:00:00Z',
				'counted' => array( $method => $amount ) + array( 'cash' => '100' ),
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $id )['status'] );
	}

	/** Invalid names and precision previously accepted by the status route. */
	public function invalid_closing_counts(): array {
		return array(
			'key' => array( 'gift.card', '1' ),
			'precision' => array( 'cash', '100.00001' ),
		);
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

	/** Credentials must identify another closure manager, without changing the cashier. */
	public function test_approve_credentials_require_another_manager(): void {
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$this->post(
			'sessions/' . $id . '/status',
			array(
				'status' => 'counting',
				'at' => '2026-09-11T11:00:00Z',
			)
		);
		$cashier = wp_get_current_user();
		$cashier->add_cap( 'view_woocommerce_pos_reports', false );
		$manager = self::factory()->user->create_and_get(
			array(
				'role' => 'subscriber',
				'user_pass' => 'approval-fixture',
			)
		);
		$credentials = array(
			'username' => $manager->user_login,
			'password' => 'approval-fixture',
		);
		$route = 'sessions/' . $id . '/approve';
		$refused = $this->post( $route, $credentials );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		wp_set_password( 'cashier-fixture', $cashier->ID );
		$cashier->add_cap( 'manage_woocommerce_pos_closures' );
		foreach ( array(
			array(
				'username' => $manager->user_login,
				'password' => 'wrong',
			),
			array(
				'username' => $cashier->user_login,
				'password' => 'cashier-fixture',
			),
			array(
				'username' => array(),
				'password' => 'approval-fixture',
			),
			array(
				'username' => $manager->user_login,
				'password' => array(),
			),
		) as $invalid ) {
			$refused = $this->post( $route, $invalid );
			$this->assertSame( 403, $refused->get_status() );
			$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
			$this->assertNull( ( new Register_Session_Store() )->get( $id )['approved_by'] );
		}
		$this->assertSame( 400, $this->post( $route, $credentials )->get_status() );
		$credentials['counted'] = array( 'cash' => '100' );
		$response = $this->post( $route, $credentials );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $manager->ID, $response->get_data()['approved_by'] );
		$this->assertSame( $manager->ID, ( new Register_Session_Store() )->get( $id )['approved_by'] );
		$this->assertSame( array( 'cash' => '100.0000' ), $response->get_data()['approved_counted'] );
		$this->assertArrayNotHasKey( 'approved_expected', $response->get_data() );
		$this->assertSame( array( 'cash' => '100.0000' ), ( new Register_Session_Store() )->get( $id )['approved_expected'] );
		foreach ( array( false, true ) as $reports ) {
			$cashier->add_cap( 'view_woocommerce_pos_reports', $reports );
			$responses = array( $this->post( 'sessions', array( 'id' => $id ) ) );
			foreach ( array( 'sessions/' . $id, 'sessions' ) as $resource ) {
				$responses[] = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/' . $resource ) );
			}
			$responses[] = $this->post( 'sessions/' . $id . '/status', array( 'status' => 'counting' ) );
			foreach ( $responses as $read ) {
				$this->assertSame( 200, $read->get_status() );
				$row = $read->get_data();
				$row = isset( $row['id'] ) ? $row : array_column( $row, null, 'id' )[ $id ];
				$this->assertSame( $reports, array_key_exists( 'approved_expected', $row ) );
				if ( $reports ) {
					$this->assertSame( array( 'cash' => '100.0000' ), $row['approved_expected'] );
				}
			}
		}
		$this->assertSame( $cashier->ID, get_current_user_id() );
		$this->assertArrayNotHasKey( 'password', $response->get_data() );
		$unchanged = $this->post( $route, $credentials );
		$this->assertSame( 409, $unchanged->get_status() );
		$this->assertSame( 'wcpos_session_transition_refused', $unchanged->get_data()['code'] );
	}

	/** The cashier who opened the drawer cannot approve its variance after a user switch. */
	public function test_approval_refuses_the_cashier_who_opened_the_session(): void {
		$opener = wp_get_current_user();
		$opener->add_cap( 'manage_woocommerce_pos_closures' );
		wp_set_password( 'opener-fixture', $opener->ID );
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$this->assertSame( $opener->ID, ( new Register_Session_Store() )->get( $id )['opened_by'] );
		// The till switches to a second cashier, who counts the drawer the opener filled.
		$second = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$second->add_cap( 'access_woocommerce_pos' );
		$second->add_cap( 'manage_woocommerce_pos_cash' );
		wp_set_current_user( $second->ID );
		$counting = $this->post(
			'sessions/' . $id . '/status',
			array(
				'status' => 'counting',
				'at' => '2026-09-11T11:00:00Z',
			)
		);
		$this->assertSame( 200, $counting->get_status() );
		// The opener is not the current user, so the requester check passes; the
		// session's own cashier must still be refused as its approver.
		$refused = $this->post(
			'sessions/' . $id . '/approve',
			array(
				'username' => $opener->user_login,
				'password' => 'opener-fixture',
				'counted' => array( 'cash' => '100' ),
			)
		);
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
		$this->assertNull( ( new Register_Session_Store() )->get( $id )['approved_by'] );
		$close = array(
			'status' => 'closed',
			'at' => '2026-09-11T12:00:00Z',
			'counted' => array( 'cash' => '100' ),
			'approver_token' => Auth::instance()->generate_access_token( $opener ),
		);
		$refused = $this->post( 'sessions/' . $id . '/status', $close );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
		$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $id )['status'] );
		// A third person with closure management still can.
		$manager = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		$close['approver_token'] = Auth::instance()->generate_access_token( $manager );
		$response = $this->post( 'sessions/' . $id . '/status', $close );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $manager->ID, $response->get_data()['approved_by'] );
	}

	/** Open and closed sessions cannot receive credential approval. */
	public function test_approve_non_counting_session_returns_conflict(): void {
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$manager = self::factory()->user->create_and_get(
			array(
				'role' => 'subscriber',
				'user_pass' => 'approval-fixture',
			)
		);
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		foreach ( array( 'open', 'counting', 'closed' ) as $status ) {
			$this->post(
				'sessions/' . $id . '/status',
				array(
					'status' => $status,
					'at' => '2026-09-11T11:00:00Z',
					'counted' => array( 'cash' => '100' ),
				)
			);
			if ( 'counting' === $status ) {
				continue;
			}
			$response = $this->post(
				'sessions/' . $id . '/approve',
				array(
					'username' => $manager->user_login,
					'password' => 'approval-fixture',
				)
			);
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'wcpos_session_transition_refused', $response->get_data()['code'] );
		}
	}

	/** A close fixture derives expected cash from a captured ledger row, not the float. */
	private function counting_session_with_cash_sale(): string {
		$fields = array_merge( $this->fields(), array( 'counted_float' => '0' ) );
		$id = $this->post( 'sessions', $fields )->get_data()['id'];
		$order = new \WC_Order();
		$order->set_total( '100' );
		$order->save();
		$payment = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '100',
				'session_id' => $id,
			)
		);
		$this->assertSame( 'captured', $payment['status'] );
		$this->assertSame( array( 'cash' => '100.0000' ), Session_Figures::for_session( ( new Register_Session_Store() )->get( $id ) )->expected() );
		$this->assertSame(
			200,
			$this->post(
				'sessions/' . $id . '/status',
				array(
					'status' => 'counting',
					'at' => '2026-09-11T11:00:00Z',
				)
			)->get_status()
		);
		return $id;
	}

	/** Over-threshold cash requires persisted approval or the existing manager token. */
	public function test_close_over_threshold_requires_approval(): void {
		$settings = static function ( $values ) {
			$values['variance_threshold'] = '5.00';
			return $values;
		};
		add_filter( 'woocommerce_pos_general_settings', $settings );
		try {
			$id = $this->counting_session_with_cash_sale();
			$route = 'sessions/' . $id . '/status';
			$close = array(
				'status' => 'closed',
				'at' => '2026-09-11T12:00:00Z',
				'counted' => array( 'cash' => '80' ),
			);
			$response = $this->post( $route, $close );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'wcpos_override_refused', $response->get_data()['code'] );
			$this->assertSame( '-20.0000', $response->get_data()['data']['variance'] );
			$this->assertSame( '5.00', $response->get_data()['data']['threshold'] );
			$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $id )['status'] );
			$manager = self::factory()->user->create_and_get(
				array(
					'role' => 'subscriber',
					'user_pass' => 'approval-fixture',
				)
			);
			$manager->add_cap( 'manage_woocommerce_pos_closures' );
			$this->assertSame(
				200,
				$this->post(
					'sessions/' . $id . '/approve',
					array(
						'username' => $manager->user_login,
						'password' => 'approval-fixture',
						'counted' => $close['counted'],
					)
				)->get_status()
			);
			$response = $this->post( $route, $close );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $manager->ID, $response->get_data()['approved_by'] );
			$id = $this->counting_session_with_cash_sale();
			$close['approver_token'] = Auth::instance()->generate_access_token( $manager );
			$response = $this->post( 'sessions/' . $id . '/status', $close );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $manager->ID, $response->get_data()['approved_by'] );
		} finally {
			remove_filter( 'woocommerce_pos_general_settings', $settings );
		}
	}

	/** Blank thresholds and exact decimal boundaries determine whether approval is needed. */
	public function test_close_threshold_boundaries_are_enforced(): void {
		foreach ( array( array( '', '80', 200 ), array( '5.00', '96', 200 ), array( '5.00', '95', 200 ), array( '5.00', '105', 200 ), array( '4.99999', '95', 403 ) ) as list( $threshold, $cash, $status ) ) {
			$settings = static function ( $values ) use ( $threshold ) {
				$values['variance_threshold'] = $threshold;
				return $values;
			};
			add_filter( 'woocommerce_pos_general_settings', $settings );
			try {
				$id = $this->counting_session_with_cash_sale();
				$response = $this->post(
					'sessions/' . $id . '/status',
					array(
						'status' => 'closed',
						'at' => '2026-09-11T12:00:00Z',
						'counted' => array( 'cash' => $cash ),
					)
				);
				$this->assertSame( $status, $response->get_status() );
				if ( 200 === $status ) {
					$this->assertNull( $response->get_data()['approved_by'] );
				} else {
					$this->assertSame( 'wcpos_override_refused', $response->get_data()['code'] );
					$this->assertSame( '-5.0000', $response->get_data()['data']['variance'] );
					$this->assertSame( '4.99999', $response->get_data()['data']['threshold'] );
				}
			} finally {
				remove_filter( 'woocommerce_pos_general_settings', $settings );
			}
		}
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
		$fields['created_at'] = '2026-09-11T12:00:00Z';
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
		$a = $this->post( 'sessions', $this->fields( 765 ) )->get_data();
		$b = $this->post(
			'sessions',
			array_merge(
				$this->fields( 765 ),
				array(
					'opened_at' => '2026-09-12T10:00:00Z',
				)
			)
		)->get_data();
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

	/** A revoked session's access token cannot approve a close once its cached revocation is gone. */
	public function test_close_with_revoked_session_approver_token_is_refused(): void {
		// Arrange.
		$id = $this->post( 'sessions', $this->fields() )->get_data()['id'];
		$route = 'sessions/' . $id . '/status';
		$close = array(
			'status' => 'counting',
			'at' => '2026-09-11T11:00:00Z',
		);
		$this->post( $route, $close );
		$close['status'] = 'closed';
		$close['counted'] = array( 'cash' => '100' );
		$manager = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$manager->add_cap( 'manage_woocommerce_pos_closures' );
		$tokens = Auth::instance()->generate_token_pair( $manager );
		$jti = Auth::instance()->validate_token( $tokens['refresh_token'], 'refresh' )->jti;
		Auth::instance()->revoke_session_with_blacklist( $manager->ID, $jti );
		delete_transient( 'wcpos_blacklist_' . $jti );
		$close['approver_token'] = $tokens['access_token'];

		// Act.
		$refused = $this->post( $route, $close );

		// Assert.
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'wcpos_override_refused', $refused->get_data()['code'] );
		$stored = ( new Register_Session_Store() )->get( $id );
		$this->assertSame( 'counting', $stored['status'] );
		$this->assertNull( $stored['approved_by'] );
		// Control: a fresh session-linked token for the same manager approves the close.
		$close['approver_token'] = Auth::instance()->generate_token_pair( $manager )['access_token'];
		$response = $this->post( $route, $close );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $manager->ID, $response->get_data()['approved_by'] );
	}
	/** Session details, movements and lists all honor the same reach. */
	public function test_session_read_outside_store_reach_reads_as_absent(): void {
		// Arrange: create directly; POST store sourcing is not under test.
		$fields = $this->fields();
		$row = ( new Register_Session_Store() )->create(
			array(
				'id' => $fields['id'],
				'register_id' => $fields['register_id'],
				'store_id' => 456,
				'opened_at_gmt' => '2026-09-11 08:00:00',
				'opened_by' => get_current_user_id(),
				'expected_float' => null,
				'counted_float' => '100',
			)
		);
		$filter = static function () {
			return array( 'store_id' => array( 789 ) );
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			foreach ( array( '', '/movements' ) as $suffix ) {
				// Act.
				$absent = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . wp_generate_uuid4() . $suffix ) );
				$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $row['id'] . $suffix ) );
				// Assert.
				$this->assertSame( $absent->get_status(), $response->get_status() );
				$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
			}
			$list = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions' ) );
			$this->assertSame( 200, $list->get_status() );
			$this->assertNotContains( $row['id'], array_column( $list->get_data(), 'id' ) );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}
	/** Both NULL and zero sessions use the same item and list reach. */
	public function test_unassigned_session_is_reachable_only_with_the_unassigned_key(): void {
		// Arrange.
		$ids = array();
		foreach ( array( null, 0 ) as $store_id ) {
			$fields = $this->fields();
			$ids[] = ( new Register_Session_Store() )->create(
				array(
					'id' => $fields['id'],
					'register_id' => $fields['register_id'],
					'store_id' => $store_id,
					'opened_at_gmt' => '2026-09-11 08:00:00',
					'opened_by' => get_current_user_id(),
					'expected_float' => null,
					'counted_float' => '100',
				)
			)['id'];
		}
		foreach ( array( true, false ) as $unassigned ) {
			$filter = static function () use ( $unassigned ) {
				return array( 'store_id' => array( 789 ) ) + ( $unassigned ? array( 'unassigned' => true ) : array() );
			};
			add_filter( Store_Reach::FILTER, $filter );
			try {
				foreach ( $ids as $id ) {
					foreach ( array( '', '/movements' ) as $suffix ) {
						// Act.
						$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $id . $suffix ) );
						// Assert.
						$this->assertSame( $unassigned ? 200 : 404, $response->get_status() );
						if ( ! $unassigned ) {
							$absent = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . wp_generate_uuid4() . $suffix ) );
							$this->assertSame( $absent->get_status(), $response->get_status() );
							$this->assertSame( $absent->get_data()['code'], $response->get_data()['code'] );
						}
					}
				}
				$list = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions' ) );
				$this->assertSame( 200, $list->get_status() );
				$actual = array_column( $list->get_data(), 'id' );
				sort( $actual );
				sort( $ids );
				$this->assertSame( $unassigned ? $ids : array(), $actual );
			} finally {
				remove_filter( Store_Reach::FILTER, $filter );
			}
		}
	}
}
