<?php
/**
 * Fiscal record writer tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Service_Groups;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Fiscal lifecycle observers and immutable corrections. */
class Test_Fiscal_Record_Writers extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Restore writer hooks between tests. */
	public function setUp(): void {
		parent::setUp();
		// WP restores hooks between tests, while service singletons survive.
		$writers = Fiscal_Record_Writers::instance();
		add_action( 'woocommerce_pos_payment_voided', array( $writers, 'handle_void' ), 10, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( $writers, 'handle_cancellation' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $writers, 'handle_refund' ), 10, 2 );
		add_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10, 2 );
		foreach ( Fiscal_Record_Store::TYPES as $type ) {
			delete_option( 'wcpos_fiscal_sequence_' . $type );
		}
	}

	/** Create an order.
	 *
	 * @param bool $pos Whether it originated at POS.
	 */
	private function order( bool $pos = true ): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( $pos ? 'woocommerce-pos' : 'checkout' );
		$order->set_status( $pos ? 'pos-open' : 'pending' );
		$order->set_total( '100.00' );
		foreach ( array(
			'_wcpos_register' => wp_generate_uuid4(),
			'_wcpos_session' => wp_generate_uuid4(),
			'_pos_store' => 12,
			'_pos_user' => get_current_user_id(),
			'_wcpos_sale_time' => '2026-09-11T10:00:00',
			'_wcpos_sale_tz' => 'Europe/Madrid',
		) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return $order;
	}

	/**
	 * Complete the order as a money command at a register would (ADR 0040 rule 1c): the request
	 * context is the register origin, so the completion is stamped and its sale record written.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function pay( \WC_Order $order ): void {
		Completion_Stamp::with_provenance(
			array(),
			static function () use ( $order ): void {
				$order->payment_complete();
			},
			$order->get_id()
		);
	}

	/**
	 * Give a fixture order a completion entry without completing it, as the anchor its sale record names.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function seed_completion( \WC_Order $order ): void {
		Completion_Fixture::seed( $order );
	}

	/**
	 * Reopen a completed order in place (the push lane's status change, without the push).
	 *
	 * @param \WC_Order $order Order.
	 */
	private function reopen( \WC_Order $order ): \WC_Order {
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'pos-open' );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * The order's sale records, oldest first.
	 *
	 * @param int $order_id Order ID.
	 */
	private function sales( int $order_id ): array {
		return array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order_id,
					'type' => 'sale',
				)
			)
		);
	}

	/** Two completions insert two sale rows through record(), the second superseding the first. */
	public function test_two_completions_write_two_sale_rows_through_record(): void {
		// Arrange.
		$order = $this->order();
		$this->pay( $order );

		// Act: reopened unchanged and completed again.
		$this->pay( $this->reopen( $order ) );

		// Assert.
		$sales   = $this->sales( $order->get_id() );
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
		$this->assertCount( 2, $sales );
		$this->assertNotSame( $sales[0]['number'], $sales[1]['number'] );
		$this->assertSame( $history[0]['id'], $sales[0]['source_id'] );
		$this->assertSame( $history[1]['id'], $sales[1]['source_id'] );
		$this->assertNull( $sales[0]['corrects_record_id'] );
		$this->assertSame( 1, $sales[0]['payload']['fiscal']['completion'] );
		$this->assertNull( $sales[0]['payload']['fiscal']['supersedes'] );
		$this->assertNull( $sales[0]['payload']['fiscal']['adjustment'] );
		$this->assertSame( $sales[0]['id'], $sales[1]['corrects_record_id'] );
		$this->assertSame( 2, $sales[1]['payload']['fiscal']['completion'] );
		$this->assertSame(
			array(
				'record_id' => $sales[0]['id'],
				'completion_id' => $history[0]['id'],
				'total' => '100.00',
			),
			$sales[1]['payload']['fiscal']['supersedes']
		);
		$this->assertSame( '0.00', $sales[1]['payload']['fiscal']['adjustment'] );
	}

	/** Completion 2 at another register names that register in its frozen document as well as its columns. */
	public function test_a_recompletion_at_another_register_names_it_in_the_payload_and_the_columns(): void {
		// Arrange: completed at register A (the till tuple), reopened.
		$registers = new \WCPOS\WooCommercePOS\Services\Register_Store();
		$a         = $registers->create(
			array(
				'name' => 'Register A',
				'store_id' => 12,
			)
		)['id'];
		$b         = $registers->create(
			array(
				'name' => 'Register B',
				'store_id' => 12,
			)
		)['id'];
		$order     = $this->order();
		$order->update_meta_data( '_wcpos_register', $a );
		$order->save();
		$this->pay( $order );
		$reopened = $this->reopen( $order );

		// Act: closed at register B.
		Completion_Stamp::with_provenance(
			array(
				'register_id' => $b,
				'sale_time' => '2026-10-20T09:15:00+02:00',
				'sale_tz' => 'Europe/Berlin',
				'sale_counter' => '42',
			),
			static function () use ( $reopened ): void {
				$reopened->payment_complete();
			},
			$reopened->get_id()
		);

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $sales );
		$this->assertSame( $a, $sales[0]['register_id'] );
		$this->assertSame( $a, $sales[0]['payload']['register']['id'] );
		$this->assertSame( $b, $sales[1]['register_id'] );
		$this->assertSame( $b, $sales[1]['payload']['register']['id'] );
		$this->assertSame( 'Register B', $sales[1]['payload']['register']['name'] );
		$this->assertSame( 'Europe/Berlin', $sales[1]['payload']['fiscal']['sale_tz'] );
		$this->assertSame( 42, $sales[1]['payload']['fiscal']['sale_counter'] );
		$this->assertSame( $a, wc_get_order( $order->get_id() )->get_meta( '_wcpos_register' ) );
	}

	/** Building completion 2's document leaves the order object the later listeners read untouched. */
	public function test_the_completion_copy_shares_no_meta_with_the_order(): void {
		// Arrange.
		$order    = $this->order();
		$till     = (string) $order->get_meta( '_wcpos_register' );
		$this->pay( $order );
		$reopened = $this->reopen( $order );
		$seen     = array();
		$listener = static function ( $completed ) use ( &$seen ): void {
			$seen[] = (string) $completed->get_meta( '_wcpos_register' );
		};
		add_action( 'wcpos_sale_completed', $listener, 20 );

		// Act: completed again at another register, after the sale writer (10) built its document.
		Completion_Stamp::with_provenance(
			array( 'register_id' => wp_generate_uuid4() ),
			static function () use ( $reopened ): void {
				$reopened->payment_complete();
			},
			$reopened->get_id()
		);
		remove_action( 'wcpos_sale_completed', $listener, 20 );

		// Assert.
		$this->assertCount( 2, $this->sales( $order->get_id() ) );
		$this->assertSame( array( $till ), $seen );
	}

	/** A re-completion in another session is late against that session's closure, not the first sale's. */
	public function test_a_recompletion_in_a_closed_session_is_late_against_that_sessions_closure(): void {
		// Arrange: completed in session A (open, no closure); session B is already closed.
		$session_a = $this->closure_session( null, 'open' );
		$session_b = $this->closure_session();
		$closure_b = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session_b ) );
		$order     = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->save();
		$take = static function ( \WC_Order $order, array $session, string $amount ): void {
			Completion_Stamp::with_provenance(
				array(
					'register_id' => $session['register_id'],
					'session_id' => $session['id'],
				),
				static function () use ( $order, $session, $amount ): void {
					Ledger::instance()->record(
						wc_get_order( $order->get_id() ),
						array(
							'id' => wp_generate_uuid4(),
							'method_id' => 'pos_cash',
							'amount' => $amount,
							'session_id' => $session['id'],
						)
					);
				},
				$order->get_id()
			);
		};
		$take( $order, $session_a, '100.00' );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();

		// Act: the extra 20.00 is taken in session B, after B's closure.
		$take( $order, $session_b, '20.00' );

		// Assert.
		$late = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 2, Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );
		$this->assertCount( 1, $late );
		$this->assertSame( $closure_b['id'], $late[0]['closure_id'] );
		$this->assertSame( '20.0000', $late[0]['payload']['expected_delta']['cash'] );
	}

	/** Each completion that lands after its session's closure writes its own late_sale. */
	public function test_each_late_completion_writes_its_own_late_sale(): void {
		// Arrange: sessions A and B are both already closed.
		$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
		$session_a = $this->closure_session();
		$session_b = $this->closure_session();
		$closure_a = $closures->create( $this->closure_fields( $session_a ) );
		$closure_b = $closures->create( $this->closure_fields( $session_b ) );
		$order     = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->save();
		$take = static function ( \WC_Order $order, array $session, string $amount ): void {
			Completion_Stamp::with_provenance(
				array(
					'register_id' => $session['register_id'],
					'session_id' => $session['id'],
				),
				static function () use ( $order, $session, $amount ): void {
					Ledger::instance()->record(
						wc_get_order( $order->get_id() ),
						array(
							'id' => wp_generate_uuid4(),
							'method_id' => 'pos_cash',
							'amount' => $amount,
							'session_id' => $session['id'],
						)
					);
				},
				$order->get_id()
			);
		};

		// Act: completion 1 in A, then reopened and completion 2 in B.
		$take( $order, $session_a, '100.00' );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();
		$take( $order, $session_b, '20.00' );
		$order   = wc_get_order( $order->get_id() );
		$history = Completion_Stamp::read( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order, $history[1] );

		// Assert.
		$late  = array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $order->get_id(),
				)
			)
		);
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $late );
		$this->assertNotSame( $late[0]['source_id'], $late[1]['source_id'] );
		$this->assertSame( $closure_a['id'], $late[0]['closure_id'] );
		$this->assertSame( $sales[0]['id'], $late[0]['corrects_record_id'] );
		$this->assertSame( $closure_b['id'], $late[1]['closure_id'] );
		$this->assertSame( $sales[1]['id'], $late[1]['corrects_record_id'] );
		$this->assertSame( Fiscal_Record_Store::digest_source_id( 'late_sale:' . $sales[1]['id'] ), $late[1]['source_id'] );
	}

	/** A later late correction in the same closed session carries only the legs no earlier one carried. */
	public function test_a_later_late_sale_carries_only_new_legs(): void {
		// Arrange: session S is already closed.
		$session = $this->closure_session();
		( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		$order = $this->order();
		$order->update_meta_data( '_wcpos_session', $session['id'] );
		$order->save();
		$in_session = static function ( \WC_Order $order, callable $work ) use ( $session ): void {
			Completion_Stamp::with_provenance(
				array(
					'register_id' => $session['register_id'],
					'session_id' => $session['id'],
				),
				$work,
				$order->get_id()
			);
		};
		$cash = static function ( \WC_Order $order, string $amount ) use ( $session ): void {
			Ledger::instance()->record(
				wc_get_order( $order->get_id() ),
				array(
					'id' => wp_generate_uuid4(),
					'method_id' => 'pos_cash',
					'amount' => $amount,
					'session_id' => $session['id'],
				)
			);
		};

		// Act: completion 1 (100.00), completion 2 adds 20.00, completion 3 adds nothing.
		$in_session( $order, static fn() => $cash( $order, '100.00' ) );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();
		$in_session( $order, static fn() => $cash( $order, '20.00' ) );
		$again = $this->reopen( $order );
		$in_session( $order, static fn() => $again->payment_complete() );

		// Assert.
		$this->assertCount( 3, Completion_Stamp::read( wc_get_order( $order->get_id() ) ) );
		$late = array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $order->get_id(),
				)
			)
		);
		$this->assertCount( 2, $late );
		$this->assertSame( array( '100.00' ), array_column( $late[0]['payload']['tender_rows'], 'amount' ) );
		$this->assertSame( array( '20.00' ), array_column( $late[1]['payload']['tender_rows'], 'amount' ) );
		$this->assertSame( '20.0000', $late[1]['payload']['expected_delta']['cash'] );
	}

	/** A refund names the standing completion's record; while that record is missing it names none, never n−1's. */
	public function test_a_refund_anchors_to_the_standing_completions_record(): void {
		// Arrange: completion 1 has a record; completion 2's insert fails.
		$order = $this->order();
		$this->pay( $order );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( wc_get_order( $order->get_id() ) );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );

		// Act: a refund while completion 2's record is missing.
		$first = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 5,
				'refund_payment' => false,
			)
		);

		// Assert: it names no sale, not completion 1's.
		$store = new Fiscal_Record_Store();
		$one   = $store->find_refund( $order->get_id(), $first->get_id() );
		$this->assertNull( $one['corrects_record_id'] );
		$this->assertNull( $one['payload']['fiscal']['corrects'] );
	}

	/** Once the standing completion's record is recovered (before any refund), a refund names it. */
	public function test_a_refund_after_recovery_names_the_recovered_record(): void {
		// Arrange: completion 2's insert fails, then a money command recovers it.
		$order = $this->order();
		$this->pay( $order );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( wc_get_order( $order->get_id() ) );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		Fiscal_Record_Writers::instance()->recover( wc_get_order( $order->get_id() ) );
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );

		// Act.
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 5,
				'refund_payment' => false,
			)
		);

		// Assert.
		$store = new Fiscal_Record_Store();
		$two   = $store->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertIsArray( $two );
		$record = $store->find_refund( $order->get_id(), $refund->get_id() );
		$this->assertSame( $two['id'], $record['corrects_record_id'] );
		$this->assertSame( $two['payload']['fiscal']['immutable_id'], $record['payload']['fiscal']['corrects'] );
	}

	/**
	 * Take cash on the order in a session, as the till's money command does.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $session Session row.
	 * @param string    $amount  Amount.
	 * @return array|\WP_Error The row.
	 */
	private function cash_in( \WC_Order $order, array $session, string $amount ) {
		$row = null;
		Completion_Stamp::with_provenance(
			array(
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			),
			static function () use ( $order, $session, $amount, &$row ): void {
				$row = Ledger::instance()->record(
					wc_get_order( $order->get_id() ),
					array(
						'id' => wp_generate_uuid4(),
						'method_id' => 'pos_cash',
						'amount' => $amount,
						'session_id' => $session['id'],
					)
				);
			},
			$order->get_id()
		);
		return $row;
	}

	/** The late correction waits for its completion's own sale row, and lands when recovery writes it. */
	public function test_a_late_sale_waits_for_its_completions_sale_row(): void {
		// Arrange: completion 1 in open session A; session B is already closed.
		$session_a = $this->closure_session( null, 'open' );
		$session_b = $this->closure_session();
		( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session_b ) );
		$order = $this->order();
		$order->update_meta_data( '_wcpos_session', $session_a['id'] );
		$order->save();
		$this->cash_in( $order, $session_a, '100.00' );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '120.00' );
		$reopened->save();
		// The sale sequence cannot advance, so record() answers null without throwing.
		$stuck = static fn( $value, $old ) => $old;

		// Act: completion 2 in B, whose sale insert fails.
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10, 2 );
		try {
			$leg = $this->cash_in( $order, $session_b, '20.00' );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10 );
		}
		$store  = new Fiscal_Record_Store();
		$before = $store->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		// The next money command on the order recovers the sale row.
		$this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $leg['id'] . '/status' ) );

		// Assert.
		$this->assertSame( array(), $before );
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
		$two     = $store->find_sale( $order->get_id(), $history[1]['id'] );
		$this->assertIsArray( $two );
		$late = $store->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $late );
		$this->assertSame( $two['id'], $late[0]['corrects_record_id'] );
	}

	/** A completion whose sale row is missing is not rebuilt once a refund has changed the order. */
	public function test_a_missing_sale_is_recovered_after_a_refund_with_its_completion_time_document(): void {
		// Arrange: completion 1's sale insert fails.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
			$request->set_body_params(
				array(
					'payment' => array(
						'id' => wp_generate_uuid4(),
						'method_id' => 'pos_cash',
						'amount' => '100.00',
					),
				)
			);
			$row = $this->server->dispatch( $request )->get_data()['payment'];
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$this->assertSame( array(), $this->sales( $order->get_id() ) );

		// Act: a refund, created after the completion, then the next money command.
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 5,
				'refund_payment' => false,
			)
		);
		$refund->set_date_created( time() + MINUTE_IN_SECONDS );
		$refund->save();
		$this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $row['id'] . '/status' ) );

		// Assert: the parked completion-time document is inserted (no refund in it); the refund, written
		// while it was missing, names no sale.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( array(), $sales[0]['payload']['refunds'] );
		$this->assertEquals( 100, $sales[0]['payload']['totals']['total_incl'] );
		$this->assertNull( ( new Fiscal_Record_Store() )->find_refund( $order->get_id(), $refund->get_id() )['corrects_record_id'] );
	}

	/**
	 * Run $work while the sale sequence cannot advance, so record() answers null without throwing.
	 *
	 * @param callable $work Work.
	 */
	private function with_sale_inserts_failing( callable $work ): void {
		$stuck = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10, 2 );
		try {
			$work();
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10 );
		}
	}

	/**
	 * The parked sale documents on the order, read from storage.
	 *
	 * @param int $order_id Order ID.
	 */
	private function parked( int $order_id ): array {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( '_wcpos_fiscal_pending' === $meta->meta_key ) {
				return (array) json_decode( (string) maybe_unserialize( $meta->meta_value ), true );
			}
		}
		return array();
	}

	/** A failed insert parks the completion-time document; recovery inserts it, whatever the order says now. */
	public function test_a_failed_sale_insert_is_parked_and_recovered_with_its_completion_time_document(): void {
		// Arrange: completed, but the sale record cannot be inserted.
		$order = $this->order();
		$order->set_billing_address_1( 'Old Street 1' );
		$order->save();
		$row = null;
		$this->with_sale_inserts_failing(
			function () use ( $order, &$row ): void {
				$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
				$request->set_body_params(
					array(
						'payment' => array(
							'id' => wp_generate_uuid4(),
							'method_id' => 'pos_cash',
							'amount' => '100.00',
						),
					)
				);
				$row = $this->server->dispatch( $request )->get_data()['payment'];
			}
		);
		$history = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
		$this->assertSame( array( $history[0]['id'] ), array_keys( $this->parked( $order->get_id() ) ) );
		// The paid order's address changes afterwards (the push's frozen-address path writes it).
		$edited = wc_get_order( $order->get_id() );
		$edited->set_billing_address_1( 'New Street 2' );
		$edited->save();

		// Act: the next money command.
		$this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $row['id'] . '/status' ) );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( $history[0]['id'], $sales[0]['source_id'] );
		$this->assertSame( 'Old Street 1', $sales[0]['payload']['customer']['billing_address']['address_1'] );
		$this->assertSame( (string) $sales[0]['number'], $sales[0]['payload']['fiscal']['receipt_number'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A parked document survives a reopen and an edit, and is inserted unchanged. */
	public function test_a_parked_document_survives_a_reopen_and_is_inserted_unchanged(): void {
		// Arrange: completion 1's insert fails; the sale is reopened and its total edited.
		$order = $this->order();
		$this->with_sale_inserts_failing(
			function () use ( $order ): void {
				$this->pay( $order );
			}
		);
		$history  = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();

		// Act.
		Fiscal_Record_Writers::instance()->recover( wc_get_order( $order->get_id() ) );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( $history[0]['id'], $sales[0]['source_id'] );
		$this->assertEquals( 100, $sales[0]['payload']['totals']['total_incl'] );
		$this->assertSame( 1, $sales[0]['payload']['fiscal']['completion'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** Replaying completion n returns row n and writes nothing. */
	public function test_replaying_completion_n_returns_row_n(): void {
		// Arrange.
		$order = $this->order();
		$this->pay( $order );
		$this->pay( $this->reopen( $order ) );
		$order   = wc_get_order( $order->get_id() );
		$history = Completion_Stamp::read( $order );
		$before  = $this->sales( $order->get_id() );

		// Act.
		do_action( 'wcpos_sale_completed', $order, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replays the completion event.
		Fiscal_Record_Writers::instance()->record_sale( $order, $history[0] );
		$replayed = ( new Fiscal_Record_Store() )->record(
			array(
				'type' => 'sale',
				'order_id' => $order->get_id(),
				'source_id' => $history[0]['id'],
				'payload' => array( 'changed' => true ),
			)
		);

		// Assert.
		$this->assertSame( $before, $this->sales( $order->get_id() ) );
		$this->assertSame( $before[0], $replayed );
	}

	/** A failed sale insert is written by the next money command, with no second completion event. */
	public function test_a_failed_sale_insert_is_recovered_by_the_next_money_command_without_a_second_completion_event(): void {
		// Arrange: the completion's sale insert fails.
		$order     = $this->order();
		$announced = array();
		$listener  = static function ( $completed, $n ) use ( &$announced ): void {
			$announced[] = $n;
		};
		$enrich    = static function () {
			throw new \Error( 'boom' );
		};
		add_action( 'wcpos_sale_completed', $listener, 5, 2 );
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
			$request->set_body_params(
				array(
					'payment' => array(
						'id' => wp_generate_uuid4(),
						'method_id' => 'pos_cash',
						'amount' => '100.00',
					),
				)
			);
			$recorded = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$this->assertSame( 200, $recorded->get_status() );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );

		// Act: the next money command on the order.
		$status = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $recorded->get_data()['payment']['id'] . '/status' ) );
		remove_action( 'wcpos_sale_completed', $listener, 5 );

		// Assert.
		$this->assertSame( 200, $status->get_status() );
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( Completion_Stamp::read( wc_get_order( $order->get_id() ) )[0]['id'], $sales[0]['source_id'] );
		$this->assertSame( array( 1 ), $announced );
	}

	/** An entry reopened before its row landed is not rebuilt from the edited order. */
	public function test_recovery_inserts_the_parked_document_of_an_entry_reopened_before_its_row_landed(): void {
		// Arrange.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$order = $this->reopen( $order );

		// Act.
		Fiscal_Record_Writers::instance()->recover( $order );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( Completion_Stamp::read( $order )[0]['id'], $sales[0]['source_id'] );
	}

	/** A reopened completion whose record never landed is never rebuilt; completion 2 still names it. */
	public function test_a_reopened_completion_is_inserted_from_its_parked_document_and_completion_2_still_names_it(): void {
		// Arrange: completion 1's sale fails; reopened and edited to 80.00; completion 2's lands.
		$order  = $this->order();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$reopened = $this->reopen( $order );
		$reopened->set_total( '80.00' );
		$reopened->save();
		$this->pay( wc_get_order( $order->get_id() ) );
		$order   = wc_get_order( $order->get_id() );
		$history = Completion_Stamp::read( $order );

		// Act: any caller asks for completion 1's record.
		Fiscal_Record_Writers::instance()->record_sale( $order, $history[0] );

		// Assert: completion 1's parked document (100.00, as completed) lands after completion 2's record,
		// which named completion 1 without a record.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $sales );
		$this->assertSame( $history[1]['id'], $sales[0]['source_id'] );
		$this->assertNull( $sales[0]['corrects_record_id'] );
		$this->assertSame( $history[0]['id'], $sales[0]['payload']['fiscal']['supersedes']['completion_id'] );
		$this->assertNull( $sales[0]['payload']['fiscal']['supersedes']['record_id'] );
		$this->assertSame( '-20.00', $sales[0]['payload']['fiscal']['adjustment'] );
		$this->assertSame( $history[0]['id'], $sales[1]['source_id'] );
		$this->assertEquals( 100, $sales[1]['payload']['totals']['total_incl'] );
		$this->assertSame( 1, $sales[1]['payload']['fiscal']['completion'] );
	}

	/** Each completion's cancellation is its own record (complete, cancel, reopen, complete, cancel). */
	public function test_cancel_after_each_completion_writes_its_own_cancellation(): void {
		// Arrange + Act.
		$order = $this->order();
		$this->pay( $order );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->pay( $this->reopen( $order ) );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );

		// Assert.
		$history       = Completion_Stamp::read( wc_get_order( $order->get_id() ) );
		$sales         = $this->sales( $order->get_id() );
		$cancellations = array_reverse(
			( new Fiscal_Record_Store() )->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'cancellation',
				)
			)
		);
		$this->assertCount( 2, $history );
		$this->assertCount( 2, $cancellations );
		$this->assertSame( $history[0]['id'], $cancellations[0]['source_id'] );
		$this->assertSame( $sales[0]['id'], $cancellations[0]['corrects_record_id'] );
		$this->assertSame( $history[1]['id'], $cancellations[1]['source_id'] );
		$this->assertSame( $sales[1]['id'], $cancellations[1]['corrects_record_id'] );
	}

	/** An online order finished at the counter is a sale at that register. */
	public function test_an_online_order_completed_at_a_register_writes_a_sale(): void {
		// Arrange.
		$order = $this->order( false );

		// Act.
		Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '100.00',
			)
		);

		// Assert.
		$this->assertFalse( wcpos_is_pos_order( wc_get_order( $order->get_id() ) ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
	}

	/** A gateway that lands a paid status without payment_complete() still writes the sale. */
	public function test_a_status_only_gateway_completion_writes_a_sale(): void {
		// Arrange: the money is on the order as a captured row.
		$order = $this->order();
		Ledger::instance()->save(
			$order,
			array(
				array(
					'id' => wp_generate_uuid4(),
					'method_id' => 'pos_cash',
					'amount' => '100.00',
					'status' => 'captured',
				),
			),
			false
		);

		// Act.
		wc_get_order( $order->get_id() )->update_status( 'processing' );

		// Assert.
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
	}

	/** Payment completion freezes one numbered document without order-meta copies. */
	public function test_payment_complete_writes_sale_once_without_meta_or_i18n(): void {
		$order = $this->order();
		$this->pay( $order );
		$order = wc_get_order( $order->get_id() );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$this->assertIsArray( $sale );
		$this->assertSame( (int) get_option( 'wcpos_fiscal_sequence_sale' ), $sale['number'] );
		$this->assertSame( $order->get_id() . ':' . $sale['number'], $sale['payload']['fiscal']['immutable_id'] );
		$this->assertSame( (string) $sale['number'], $sale['payload']['fiscal']['receipt_number'] );
		$this->assertArrayNotHasKey( 'i18n', $sale['payload'] );
		foreach ( array( 'snapshot', 'sequence', 'snapshot_checksum', 'snapshot_created_at_gmt', 'submission_status', 'submission_status_updated_at' ) as $key ) {
			$this->assertSame( '', $order->get_meta( '_wcpos_receipt_' . $key, true ) );
		}
		$this->assertSame( $order->get_meta( '_wcpos_register' ), $sale['register_id'] );
		$this->assertSame( 12, $sale['store_id'] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		do_action( 'wcpos_sale_completed', $order, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replays the completion event.
		$this->assertSame( array( $sale ), $store->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** Building must succeed before a number is taken, without undoing payment. */
	public function test_failed_sale_build_consumes_no_number(): void {
		$order = $this->order();
		$before = get_option( 'wcpos_fiscal_sequence_sale' );
		$filter = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'woocommerce_pos_receipt_data', $filter );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'woocommerce_pos_receipt_data', $filter );
		}

		$this->assertNull( ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
		$this->assertSame( $before, get_option( 'wcpos_fiscal_sequence_sale' ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	/** Extension errors are contained and leave a visible recovery signal. */
	public function test_enrich_filter_throwing_error_is_contained(): void {
		$order = $this->order();
		$failed = array();
		$enrich = static function () {
			throw new \Error( 'boom' );
		};
		$observe = static function ( $type ) use ( &$failed ) {
			$failed[] = $type;
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		add_action( 'woocommerce_pos_fiscal_record_failed', $observe );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
			remove_action( 'woocommerce_pos_fiscal_record_failed', $observe );
		}

		$this->assertNull( ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'could not be written', implode( '\n', wp_list_pluck( $notes, 'content' ) ) );
		$this->assertSame( array( 'sale' ), $failed );
	}

	/** Enrichers receive the minted identity and the label-free document. */
	public function test_enrich_filter_sees_minted_number_and_no_i18n(): void {
		$order = $this->order();
		$seen = null;
		$enrich = static function ( array $snapshot, int $id ) use ( &$seen ) {
			$seen = array( $snapshot, $id );
			$snapshot['fiscal']['qr_payload'] = 'ENRICHED';
			return $snapshot;
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich, 10, 2 );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$sale = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$this->assertSame( $sale['number'], $seen[0]['fiscal']['sequence'] );
		$this->assertSame( $order->get_id(), $seen[1] );
		$this->assertArrayNotHasKey( 'i18n', $seen[0] );
		$this->assertSame( 'ENRICHED', $sale['payload']['fiscal']['qr_payload'] );
	}

	/** Invalid extension returns cannot discard the sale. */
	public function test_enrich_filter_returning_non_array_is_ignored(): void {
		$order = $this->order();
		$enrich = static function () {
			return 'oops';
		};
		add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		try {
			$this->pay( $order );
		} finally {
			remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
		}
		$sale = ( new Fiscal_Record_Store() )->find_sale( $order->get_id() );
		$this->assertIsArray( $sale );
		$this->assertSame( '', $sale['payload']['fiscal']['qr_payload'] );
		$this->assertSame( $order->get_id() . ':' . $sale['number'], $sale['payload']['fiscal']['immutable_id'] );
	}

	/** A storefront webhook arms the writer before completing a POS payment. */
	public function test_payment_complete_from_storefront_lane_writes_sale(): void {
		$writers = Fiscal_Record_Writers::instance();
		remove_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10 );
		$instance = new \ReflectionProperty( Fiscal_Record_Writers::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		Service_Groups::reset();
		Service_Groups::arm_order_group();
		try {
			// The webhook request has no money command in flight: the captured row is the register origin.
			$order = $this->order();
			Ledger::instance()->save(
				$order,
				array(
					array(
						'id' => wp_generate_uuid4(),
						'method_id' => 'pos_cash',
						'amount' => '100.00',
						'status' => 'captured',
					),
				),
				false
			);
			wc_get_order( $order->get_id() )->payment_complete();
			$this->assertIsArray( ( new Fiscal_Record_Store() )->find_sale( $order->get_id() ) );
		} finally {
			$instance->setValue( null, $writers );
			Service_Groups::reset();
		}
	}

	/** A storefront completion (no register origin) writes no sale, and its refund no record. */
	public function test_a_storefront_completion_writes_no_sale(): void {
		$order = $this->order( false );
		$order->payment_complete();
		$this->assertFalse( get_option( 'wcpos_fiscal_sequence_sale' ) );
		$fresh = wc_get_order( $order->get_id() );
		foreach ( array( 'snapshot', 'sequence', 'snapshot_checksum', 'snapshot_created_at_gmt', 'submission_status', 'submission_status_updated_at' ) as $key ) {
			$this->assertSame( '', $fresh->get_meta( '_wcpos_receipt_' . $key, true ) );
		}
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 10,
				'reason' => 'Return',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** The refund hash covers the stored dataset, with only its hash blanked. */
	public function test_refund_document_hash_covers_stored_bytes_without_i18n(): void {
		$order = $this->order();
		$this->pay( $order );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 10,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$store = new Fiscal_Record_Store();
		$row = $store->find_refund( $order->get_id(), $refund->get_id() );
		$this->assertIsArray( $row );
		$stored = $row['payload'];
		$this->assertArrayNotHasKey( 'i18n', $stored );
		$copy = $stored;
		$copy['fiscal']['hash'] = '';
		$this->assertSame( Fiscal_Record_Store::checksum( wp_json_encode( $copy ) ), $stored['fiscal']['hash'] );
		$this->assertSame( Fiscal_Record_Store::checksum( wp_json_encode( $stored ) ), $row['checksum'] );
	}

	/** Captured manual void records before and after rows. */
	public function test_captured_manual_void_records_before_and_after_rows(): void {
		$order = $this->order();
		$store = new Fiscal_Record_Store();
		// A sale anchor on an in-progress order (a reopened sale): Ledger refuses voids on completed orders.
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		Completion_Fixture::reopen( $order );
		$sale = $store->find_sale( $order->get_id() );
		$row = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '20.00',
			)
		);
		$this->assertIsArray( $row );
		$applied = Ledger::instance()->void( $order, $row['id'], 'Changed mind' );
		$this->assertSame( 'voided', $applied['status'] );
		$records = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'void',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
		$this->assertSame( $row, $records[0]['payload']['row'] );
		$this->assertSame( $applied, $records[0]['payload']['voided_row'] );
		$this->assertSame( 'Changed mind', $records[0]['payload']['reason'] );
		$this->assertSame( get_current_user_id(), $records[0]['payload']['actor_id'] );
		$this->assertNull( $records[0]['device_time'] );
		$this->assertNull( $records[0]['device_tz'] );
	}

	/** Pending and authorized voids fire action but write no records. */
	public function test_pending_and_authorized_voids_fire_action_but_write_no_records(): void {
		foreach ( array( 'pending', 'authorized' ) as $status ) {
			$order = $this->order();
			$row = array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '20.00',
				'status' => $status,
				'kind' => 'cash',
				'capture_mode' => 'manual',
			);
			Ledger::instance()->save( $order, array( $row ) );
			$before = did_action( 'woocommerce_pos_payment_voided' );
			$applied = Ledger::instance()->void( $order, $row['id'], 'Cancelled' );
			$this->assertSame( 'voided', $applied['status'] );
			$this->assertSame( $before + 1, did_action( 'woocommerce_pos_payment_voided' ) );
			$this->assertSame(
				array(),
				( new Fiscal_Record_Store() )->list(
					array(
						'order_id' => $order->get_id(),
						'type' => 'void',
					)
				)
			);
		}
	}

	/** Cancellation requires pos sale and is write once. */
	public function test_cancellation_requires_pos_sale_and_is_write_once(): void {
		$store = new Fiscal_Record_Store();
		$order = $this->order();
		$this->pay( $order );
		$sale = $store->find_sale( $order->get_id() );
		$order->update_status( 'cancelled' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_order_status_cancelled', $order->get_id(), $order );
		$records = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'cancellation',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
		$this->assertSame(
			array(
				'record_id' => $sale['id'],
				'number' => $sale['number'],
				'immutable_id' => $sale['payload']['fiscal']['immutable_id'],
				'checksum' => $sale['checksum'],
			),
			$records[0]['payload']['sale']
		);
		$cart = $this->order();
		$cart->update_status( 'cancelled' );
		$this->assertSame( array(), $store->list( array( 'order_id' => $cart->get_id() ) ) );
		$online = $this->order( false );
		$online->payment_complete();
		$online->update_status( 'cancelled' );
		$this->assertSame( array(), $store->list( array( 'order_id' => $online->get_id() ) ) );
	}

	/** Admin refunds get independent numbers and sale link. */
	public function test_admin_refunds_get_independent_numbers_and_sale_link(): void {
		$order = $this->order();
		$this->pay( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		foreach ( array( 1, 2 ) as $number ) {
			$refund = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount' => 10,
					'reason' => 'Return',
					'refund_payment' => false,
				)
			);
			$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
			do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
			$records = $store->list(
				array(
					'order_id' => $order->get_id(),
					'type' => 'refund',
				)
			);
			$this->assertCount( $number, $records );
			$this->assertSame( $number, $records[0]['number'] );
			$this->assertSame( $refund->get_id(), $records[0]['refund_id'] );
			$this->assertSame( $sale['id'], $records[0]['corrects_record_id'] );
			$this->assertSame( 'refund', $records[0]['payload']['fiscal']['document_type'] );
			$this->assertEquals( 10, $records[0]['payload']['totals']['total_incl'] );
			$this->assertSame( 'unallocated', $records[0]['payload']['fiscal']['extra_fields']['allocation'] );
			$this->assertSame( array(), $records[0]['payload']['fiscal']['extra_fields']['allocations'] );
			$this->assertNull( $records[0]['session_id'] );
			$this->assertNull( $records[0]['register_id'] );
			$this->assertNull( $records[0]['device_time'] );
			$this->assertNull( $records[0]['device_tz'] );
		}
	}

	/** Refund seam preserves allocations. */
	public function test_refund_seam_preserves_allocations(): void {
		$order = $this->order();
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( 5 );
		$allocations = array(
			array(
				'payment_id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '5.00',
			),
		);
		$refund->update_meta_data( '_wcpos_refund_allocations', $allocations );
		$refund->save();
		Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
		$payload = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'refund',
			)
		)[0]['payload'];
		$this->assertSame( $allocations, $payload['fiscal']['extra_fields']['allocations'] );
		$this->assertSame( 'allocated', $payload['fiscal']['extra_fields']['allocation'] );
	}
	/** Admin refund freezes positive lines and single cash allocation. */
	public function test_admin_refund_freezes_positive_lines_and_single_cash_allocation(): void {
		$order = $this->order();
		$item = new \WC_Order_Item_Product();
		$item->set_name( 'Returned item' );
		$item->set_quantity( 2 );
		$item->set_subtotal( 100 );
		$item->set_total( 100 );
		$order->add_item( $item );
		$order->save();
		$row = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '100.00',
			)
		);
		$this->assertIsArray( $row );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 25,
				'refund_payment' => false,
				'line_items' => array(
					$item->get_id() => array(
						'qty' => 1,
						'refund_total' => 25,
					),
				),
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$record = $store->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'refund',
			)
		)[0];
		$data = $record['payload'];
		$this->assertSame( 'refund', $data['fiscal']['document_type'] );
		$this->assertSame( '1', $data['fiscal']['receipt_number'] );
		$this->assertSame( $sale['payload']['fiscal']['immutable_id'], $data['fiscal']['corrects'] );
		$this->assertEquals( 25, $data['lines'][0]['line_total_excl'] );
		$this->assertEquals( 1, $data['lines'][0]['qty'] );
		$this->assertSame( 'allocated', $data['fiscal']['extra_fields']['allocation'] );
		$this->assertSame(
			array(
				array(
					'payment_id' => $row['id'],
					'method_id' => 'pos_cash',
					'amount' => '25.00',
				),
			),
			$data['fiscal']['extra_fields']['allocations']
		);
		$this->assertSame( $row['id'], $data['payments'][0]['payment_id'] );
		$this->assertEquals( 25, $data['payments'][0]['amount'] );
		$order->set_billing_first_name( 'Edited later' );
		$item->set_total( 80 );
		$item->save();
		$order->save();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce lifecycle hooks.
		do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
		$this->assertSame( $data, $store->get( $record['id'] )['payload'] );
	}

	/** Refund with two counting rows without meta is unallocated. */
	public function test_refund_with_two_counting_rows_without_meta_is_unallocated(): void {
		$order = $this->order();
		$rows = array();
		// Two captured rows: an authorization no longer counts (ADR 0040 D6).
		foreach ( array( 'captured', 'captured' ) as $status ) {
			$rows[] = array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '50.00',
				'status' => $status,
			);
		}
		Ledger::instance()->save( $order, $rows );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => 10,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$data = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type' => 'refund',
			)
		)[0]['payload'];
		$this->assertSame( 'unallocated', $data['fiscal']['extra_fields']['allocation'] );
		$this->assertSame( array(), $data['fiscal']['extra_fields']['allocations'] );
		$this->assertSame( array(), $data['payments'] );
	}
	/** The audit payload freezes all tenders and variance for the counted tenders. */
	public function test_late_sale_freezes_expected_delta_for_every_tender(): void {
		$session = $this->closure_session();
		$fields = $this->closure_fields( $session );
		$fields['counted'] = array(
			'cash' => '101',
			'card' => '30',
		);
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $fields );
		$order = $this->closure_ledger( $session );
		// Equal-second ordering: the sale row arrives in the closure's own second.
		global $wpdb;
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => $closure['received_at_gmt'] ), array( 'id' => $sale['id'] ) );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame(
			array(
				'cash' => '50.0000',
				'card' => '30.0000',
			),
			$records[0]['payload']['expected_delta']
		);
		$this->assertSame(
			array(
				'cash' => '-50.0000',
				'card' => '-30.0000',
			),
			$records[0]['payload']['variance_delta']
		);
	}

	/** Late sales have one correction, while replay of an included sale has none. */
	public function test_late_sale_is_replay_safe_and_does_not_mutate_closure(): void {
		$session = $this->closure_session();
		$store = new \WCPOS\WooCommercePOS\Services\Closure_Store();
		$closure = $store->create( $this->closure_fields( $session ) );
		$order = $this->closure_ledger( $session );
		$rows = Ledger::instance()->read( $order );
		$rows[0]['method_id'] = 'extension_cash_drawer';
		Ledger::instance()->save( $order, $rows, false );
		$writers = Fiscal_Record_Writers::instance();
		$this->seed_completion( $order );
		$writers->record_sale( $order );
		$writers->record_sale( $order );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $closure['id'], $records[0]['closure_id'] );
		$this->assertSame( '50.0000', $records[0]['payload']['expected_delta']['cash'] );
		$this->assertSame( '-50.0000', $records[0]['payload']['variance_delta']['cash'] );
		// The fixture binds two captured rows to the session: cash 50 (refunded 10) and card 30.
		$this->assertCount( 2, $records[0]['payload']['tender_rows'] );
		$this->assertSame( $closure, $store->get( $closure['id'] ) );
		$open = $this->closure_session( null, 'open' );
		$early = $this->closure_ledger( $open );
		$this->seed_completion( $early );
		$writers->record_sale( $early );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $early->get_id(),
				)
			)
		);
		$sessions = new \WCPOS\WooCommercePOS\Services\Register_Session_Store();
		$counting = $sessions->transition(
			$open,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
		$store->create( $this->closure_fields( $counting ) );
		$writers->record_sale( $early );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $early->get_id(),
				)
			)
		);
		$order->delete( true );
		$early->delete( true );
	}

	/** Replaying a sale captured before close does not duplicate its frozen ledger. */
	public function test_repaired_preclosure_sale_is_not_a_late_sale(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		global $wpdb;
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => '2000-01-01 00:00:00' ), array( 'id' => $sale['id'] ) );
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );

		Fiscal_Record_Writers::instance()->record_sale( $order );

		$records = ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $records );
		$this->assertSame( 'sale', $records[0]['type'] );
		$this->assertSame( $closure, ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $closure['id'] ) );
		$order->delete( true );
	}
	/** Only the newly arrived leg belongs in the correction. */
	public function test_late_sale_freezes_only_the_legs_the_closure_did_not_count(): void {
		$session = $this->closure_session( null, 'open' );
		$order = $this->closure_ledger( $session );
		$ledger = Ledger::instance();
		$rows = array( $ledger->read( $order )[0] );
		$ledger->save( $order, $rows, false );
		$order->set_status( 'pos-partial' );
		$order->save();
		$session = ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->transition(
			$session,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		$leg_b = wp_generate_uuid4();
		$rows[] = array_merge(
			$rows[0],
			array(
				'id' => $leg_b,
				'amount' => '20',
			)
		);
		$ledger->save( $order, $rows, false );
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'type' => 'late_sale',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( array( $leg_b ), array_column( $records[0]['payload']['tender_rows'], 'id' ) );
		$this->assertSame( '20.0000', $records[0]['payload']['expected_delta']['cash'] );
	}

	/** Arrival clocks cannot turn counted legs into late sales. */
	public function test_sale_counted_by_the_closure_is_not_late_whatever_its_snapshot_clock(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		global $wpdb;
		$this->seed_completion( $order );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		// The sale record's arrival time is the clock the old rule compared; push it past the close.
		$wpdb->update( $store->table_name(), array( 'received_at_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $closure['received_at_gmt'] . ' UTC' ) + HOUR_IN_SECONDS ) ), array( 'id' => $sale['id'] ) );
		Fiscal_Record_Writers::instance()->record_sale( $order );
		$this->assertSame(
			array(),
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_sale',
					'order_id' => $order->get_id(),
				)
			)
		);
	}
}
