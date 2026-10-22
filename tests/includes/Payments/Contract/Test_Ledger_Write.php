<?php
/**
 * The ledger write as an ordered unit of work (ADR 0040, amended 2026-10-22).
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WP_UnitTestCase;

/**
 * `Ledger::write()` through its interface, on posts storage (`Test_Ledger_Write_HPOS` re-runs it on
 * the orders table): rows (S1), then the paid transition (S2), then the completion (S3), each read
 * back; a failure at any step is healed by retrying the same command.
 */
class Test_Ledger_Write extends WP_UnitTestCase {
	/**
	 * The stocked product on the order.
	 *
	 * @var \WC_Product
	 */
	private $product;

	/** Fresh request context per test. */
	public function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_manage_stock', 'yes' );
		Completion_Stamp::reset();
	}

	/** Forget the request context. */
	public function tearDown(): void {
		Completion_Stamp::reset();
		parent::tearDown();
	}

	/** Listeners on the paid hooks read storage: rows before the paid transition, the paid status before the completion. */
	public function test_write_completing_stores_rows_before_paid_and_paid_before_completion(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '92.95' );
		$seen  = array();
		$trace = function ( string $hook ) use ( $order, $row, &$seen ): void {
			$seen[ $hook ] = array(
				'row'    => in_array( $row['id'], array_column( $this->stored_rows( $order->get_id() ), 'id' ), true ),
				'status' => $this->stored_status( $order->get_id() ),
			);
		};
		add_action( 'woocommerce_pre_payment_complete', fn() => $trace( 'pre_payment_complete' ), 1 );
		add_action( 'woocommerce_order_status_changed', fn() => $trace( 'status_changed' ), 1 );
		add_action( 'woocommerce_payment_complete', fn() => $trace( 'payment_complete' ), 1 );
		add_action( 'wcpos_sale_completed', fn() => $trace( 'sale_completed' ), 1 );

		// Act.
		$written = Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( $row ) );

		// Assert.
		$this->assertEqualsCanonicalizing( array( 'pre_payment_complete', 'status_changed', 'payment_complete', 'sale_completed' ), array_keys( $seen ) );
		$this->assertSame( 'pre_payment_complete', array_key_first( $seen ) );
		$this->assertTrue( $seen['pre_payment_complete']['row'], 'The row is stored before the paid transition begins.' );
		$this->assertSame( 'pos-open', $seen['pre_payment_complete']['status'] );
		$this->assertTrue( $seen['status_changed']['row'] );
		$this->assertSame( 'completed', $seen['status_changed']['status'] );
		$this->assertSame( 'completed', $seen['sale_completed']['status'], 'The paid status is stored before the completion is announced.' );
		$this->assertSame( 'completed', $written->order->get_status() );
		$this->assertSame( 1, (int) $written->completion['n'] );
		$this->assertSame( array( $row['id'] ), $written->completion['payment_ids'] );
		$this->assertSame( array( $row['id'] ), array_column( $written->rows, 'id' ) );
	}

	/**
	 * A failure at each step is retryable, and retrying the same command lands one completion, one
	 * fiscal sale, one stock reduction and the rows of a single run.
	 *
	 * @dataProvider failing_stages
	 *
	 * @param string $stage The step that fails once.
	 */
	public function test_write_failing_at_stage_then_retried_lands_one_completion( string $stage ): void {
		// Arrange.
		global $wpdb;
		$order  = $this->create_pos_order();
		$id     = $order->get_id();
		$row    = $this->row( '92.95' );
		$undo   = $this->inject( $stage, $id );
		$caught = null;

		// Act.
		try {
			$this->pay( $id, $row );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$wpdb->last_error = '';
		$this->pay( $id, $row );

		// Assert.
		$this->assertInstanceOf( Ledger_Persistence_Exception::class, $caught );
		$this->assertSame( $stage, $caught->stage );
		$saved = $this->fresh( $id );
		$this->assertSame( 'completed', $saved->get_status() );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertSame( array( $row['id'] ), Completion_History::of( $saved )->standing()['payment_ids'] );
		$this->assertSame(
			1,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $id,
					'type'     => 'sale',
				)
			)
		);
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
		$rows = $this->stored_rows( $id );
		$this->assertSame( array( $row['id'] ), array_column( $rows, 'id' ) );
		$this->assertSame( 'captured', $rows[0]['status'] );
	}

	/** The steps a write can fail at. */
	public function failing_stages(): array {
		return array(
			'lock'       => array( 'lock' ),
			'rows'       => array( 'rows' ),
			'paid'       => array( 'paid' ),
			'completion' => array( 'completion' ),
		);
	}

	/** One read-back miss is retried from a fresh read: the change runs again and the write lands. */
	public function test_write_rows_missing_once_retries_from_fresh_read(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '50.00' );
		$runs  = 0;
		$miss  = $this->lose_rows( $order->get_id(), 1 );

		// Act.
		$written = Ledger::instance()->write(
			$order,
			function ( Ledger_Draft $draft ) use ( $row, &$runs ) {
				++$runs;
				$draft->put( $row );
				return 'recorded';
			}
		);
		$miss();

		// Assert.
		$this->assertSame( 2, $runs );
		$this->assertSame( 'recorded', $written->answer );
		$this->assertSame( array( $row['id'] ), array_column( $this->stored_rows( $order->get_id() ), 'id' ) );
		$this->assertSame( 'pos-partial', $this->stored_status( $order->get_id() ) );
	}

	/**
	 * Inside an order save, transition or payment hook a write is refused, and stores nothing (I3).
	 *
	 * @dataProvider order_hooks
	 *
	 * @param string $hook Hook name.
	 */
	public function test_write_inside_order_hook_is_refused( string $hook ): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$caught = null;
		remove_all_actions( $hook );
		add_action(
			$hook,
			function () use ( $order, &$caught ): void {
				try {
					Ledger::instance()->write( $order->get_id(), fn( Ledger_Draft $draft ) => $draft->put( $this->row( '5.00' ) ) );
				} catch ( Ledger_Persistence_Exception $error ) {
					$caught = $error;
				}
			}
		);

		// Act.
		do_action( $hook, $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires the WooCommerce hook under test.

		// Assert.
		$this->assertInstanceOf( Ledger_Persistence_Exception::class, $caught );
		$this->assertSame( 'lock', $caught->stage );
		$this->assertStringContainsString( $hook, $caught->getMessage() );
		$this->assertSame( array(), $this->stored_rows( $order->get_id() ) );
	}

	/** The hooks a write refuses to run inside. */
	public function order_hooks(): array {
		$hooks = array( 'save_post', 'save_post_shop_order', 'wp_insert_post', 'pre_post_update', 'post_updated', 'woocommerce_pre_payment_complete', 'woocommerce_payment_complete', 'woocommerce_order_status_changed', 'woocommerce_order_status_processing', 'woocommerce_order_status_pos-open_to_processing', 'woocommerce_payment_complete_order_status_pos-open', 'woocommerce_before_order_object_save', 'woocommerce_after_order_object_save', 'woocommerce_new_order', 'woocommerce_update_order' );
		return array_combine( $hooks, array_map( static fn( $hook ) => array( $hook ), $hooks ) );
	}

	/** A write from a listener inside WooCommerce's own payment_complete() is refused there (I3). */
	public function test_write_inside_real_payment_complete_is_refused(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$caught = null;
		add_action(
			'woocommerce_payment_complete',
			function ( $id ) use ( &$caught ): void {
				try {
					Ledger::instance()->write( (int) $id );
				} catch ( Ledger_Persistence_Exception $error ) {
					$caught = $error;
				}
			}
		);

		// Act.
		$order->payment_complete();

		// Assert.
		$this->assertInstanceOf( Ledger_Persistence_Exception::class, $caught );
		$this->assertSame( 'lock', $caught->stage );
	}

	/** On posts storage a write from a save_post_shop_order listener, inside the order's own save, is refused and does not recurse. */
	public function test_write_inside_save_post_shop_order_is_refused_without_recursing(): void {
		// Arrange.
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'Orders are posts only on posts storage.' );
		}
		$order  = $this->create_pos_order();
		$calls  = 0;
		$caught = null;
		add_action(
			'save_post_shop_order',
			function ( $post_id ) use ( $order, &$calls, &$caught ): void {
				if ( (int) $post_id !== $order->get_id() || ++$calls > 3 ) {
					return; // The bound keeps a missing refusal a failure, not a runaway recursion.
				}
				try {
					Ledger::instance()->write( $order->get_id(), fn( Ledger_Draft $draft ) => $draft->put( $this->row( '5.00' ) ) );
				} catch ( Ledger_Persistence_Exception $error ) {
					$caught = $error;
				}
			}
		);
		$saving = $this->fresh( $order->get_id() );
		$saving->set_customer_note( 'Saved' );

		// Act.
		$saving->save();

		// Assert.
		$this->assertSame( 1, $calls );
		$this->assertInstanceOf( Ledger_Persistence_Exception::class, $caught );
		$this->assertSame( 'lock', $caught->stage );
		$this->assertSame( array(), $this->stored_rows( $order->get_id() ) );
	}

	/** A zero-total close with nothing to change still stores the ledger before it is paid, so a lost completion heals. */
	public function test_write_no_change_zero_total_close_stores_ledger_and_heals_lost_completion(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_total( '0.00' );
		$order->save();
		$this->assertFalse( $this->fresh( $order->get_id() )->meta_exists( Ledger::META_KEY ), 'Precondition: no ledger yet.' );
		$undo   = $this->inject( 'completion', $order->get_id() );
		$caught = null;
		try {
			Ledger::instance()->write( $order->get_id(), null, Ledger::MODE_CLOSE );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$this->assertSame( 'completion', $caught ? $caught->stage : null, 'Precondition: paid, completion lost.' );
		$this->assertTrue( $this->fresh( $order->get_id() )->meta_exists( Ledger::META_KEY ), 'S1 stored the ledger before the paid transition.' );

		// Act.
		$written = Ledger::instance()->write( $order->get_id() );

		// Assert.
		$this->assertSame( 1, (int) $written->completion['n'] );
	}

	/** A listener that threw out of an order save WooCommerce swallowed does not leave later writes refused. */
	public function test_write_after_swallowed_listener_throw_is_not_refused(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '5.00' );
		$throw = static function (): void {
			throw new \RuntimeException( 'A listener failed.' );
		};
		add_action( 'woocommerce_before_order_object_save', $throw );
		$saving = $this->fresh( $order->get_id() );
		$saving->set_customer_note( 'Saved' );
		$saving->save();
		remove_action( 'woocommerce_before_order_object_save', $throw );
		$this->assertContains( 'woocommerce_before_order_object_save', $GLOBALS['wp_current_filter'], 'Precondition: the swallowed throw left the hook on the stack.' );

		// Act.
		Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( $row ) );

		// Assert.
		$this->assertSame( array( $row['id'] ), array_column( $this->stored_rows( $order->get_id() ), 'id' ) );
	}

	/** Inside a caller's with_lock the write nests: the caller still holds the lock after it. */
	public function test_write_nested_in_with_lock_keeps_outer_lock(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$held  = null;

		// Act.
		Order_Lock::instance()->with_lock(
			$order->get_id(),
			function () use ( $order, &$held ): void {
				Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( $this->row( '5.00' ) ) );
				$held = $this->held_count( $order->get_id() );
			}
		);

		// Assert.
		$this->assertSame( 1, $held );
		$this->assertSame( 0, $this->held_count( $order->get_id() ) );
	}

	/** A row the change left untouched keeps its stored updated_at_gmt; the one it changed is restamped. */
	public function test_write_untouched_row_keeps_updated_at_gmt(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$kept    = $this->row( '10.00' );
		$changed = $this->row( '20.00', 'pending', array( 'capture_mode' => 'gateway' ) );
		Ledger::instance()->write(
			$order,
			function ( Ledger_Draft $draft ) use ( $kept, $changed ): void {
				$draft->put( $kept );
				$draft->put( $changed );
			}
		);
		$this->seed_stamps( $order->get_id(), '2020-01-01T00:00:00+00:00' );

		// Act.
		Ledger::instance()->write(
			$order,
			function ( Ledger_Draft $draft ) use ( $changed ): void {
				$draft->put( array_merge( $draft->row( $changed['id'] ), array( 'provider_refs' => array( 'reader' => 'tmr_1' ) ) ) );
			}
		);

		// Assert.
		$rows = array_column( $this->stored_rows( $order->get_id() ), null, 'id' );
		$this->assertSame( '2020-01-01T00:00:00+00:00', $rows[ $kept['id'] ]['updated_at_gmt'] );
		$this->assertNotSame( '2020-01-01T00:00:00+00:00', $rows[ $changed['id'] ]['updated_at_gmt'] );
	}

	/** A caller holding an order read before another row landed loses nothing: the write reads storage. */
	public function test_write_from_stale_order_keeps_concurrent_rows(): void {
		// Arrange.
		$stale = $this->create_pos_order();
		$first = $this->row( '10.00' );
		$late  = $this->row( '20.00' );
		Ledger::instance()->write( $stale->get_id(), fn( Ledger_Draft $draft ) => $draft->put( $first ) );

		// Act.
		Ledger::instance()->write( $stale, fn( Ledger_Draft $draft ) => $draft->put( $late ) );

		// Assert.
		$this->assertSame( array( $first['id'], $late['id'] ), array_column( $this->stored_rows( $stale->get_id() ), 'id' ) );
		$this->assertSame( array(), Ledger::instance()->read( $stale ), 'Precondition: the caller\'s object never saw a row.' );
	}

	/**
	 * A change that breaks a stored row's invariants is a programming error: it throws and stores nothing (I5).
	 *
	 * @dataProvider invariant_breaks
	 *
	 * @param array    $stored Stored row overrides.
	 * @param callable $break  fn( Ledger_Draft, array $row ).
	 */
	public function test_write_breaking_row_invariant_throws_logic_exception( array $stored, callable $break ): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '10.00', 'captured', $stored );
		$this->seed_rows( $order->get_id(), array( $row ) );
		$before = $this->stored_rows( $order->get_id() );
		$this->expectException( \LogicException::class );

		// Act.
		try {
			Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $break( $draft, $row ) );
		} finally {
			// Assert.
			$this->assertSame( $before, $this->stored_rows( $order->get_id() ) );
		}
	}

	/** The invariants a change cannot break. */
	public function invariant_breaks(): array {
		$edit = static fn( array $fields ) => static fn( Ledger_Draft $draft, array $row ) => $draft->put( array_merge( $row, $fields ) );
		return array(
			'captured amount changes'        => array( array(), $edit( array( 'amount' => '11.00' ) ) ),
			'captured method changes'        => array( array(), $edit( array( 'method_id' => 'pos_card' ) ) ),
			'captured currency changes'      => array( array(), $edit( array( 'currency' => 'EUR' ) ) ),
			'captured moves to failed'       => array( array(), $edit( array( 'status' => 'failed' ) ) ),
			'captured gateway row is voided' => array( array( 'capture_mode' => 'gateway' ), $edit( array( 'status' => 'voided' ) ) ),
			'captured row vanishes'          => array( array(), static fn( Ledger_Draft $draft, array $row ) => $draft->drop( $row['id'] ) ),
			'closure id is rewritten'        => array( array( 'closure_id' => 'c1' ), $edit( array( 'closure_id' => 'c2' ) ) ),
			'pending row with refunds goes'  => array(
				array(
					'status' => 'pending',
					'refunds' => array(
						array(
							'amount' => '1.00',
							'status' => 'pending',
						),
					),
				),
				static fn( Ledger_Draft $draft, array $row ) => $draft->drop( $row['id'] ),
			),
		);
	}

	/** What the invariants allow: a manual captured row is voided, a refused (pending) intent vanishes. */
	public function test_write_voiding_manual_row_and_dropping_pending_intent_is_allowed(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$cash    = $this->row( '10.00' );
		$refused = $this->row( '20.00', 'pending', array( 'capture_mode' => 'gateway' ) );
		$this->seed_rows( $order->get_id(), array( $cash, $refused ) );

		// Act.
		Ledger::instance()->write(
			$order,
			function ( Ledger_Draft $draft ) use ( $cash, $refused ): void {
				$draft->put( array_merge( $cash, array( 'status' => 'voided' ) ) );
				$draft->drop( $refused['id'] );
			}
		);

		// Assert.
		$rows = $this->stored_rows( $order->get_id() );
		$this->assertSame( array( $cash['id'] ), array_column( $rows, 'id' ) );
		$this->assertSame( 'voided', $rows[0]['status'] );
	}

	/** Hold stages the rows and projects nothing: a covering row neither moves the status nor completes. */
	public function test_write_hold_mode_stores_rows_without_projection(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '92.95' );

		// Act.
		$written = Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( $row ), Ledger::MODE_HOLD );

		// Assert.
		$this->assertSame( 'pos-open', $this->stored_status( $order->get_id() ) );
		$this->assertNull( $written->completion );
		$this->assertSame( array( $row['id'] ), array_column( $this->stored_rows( $order->get_id() ), 'id' ) );
	}

	/** A covered open sale stays open under a write that changes a row (rule 3); a replay with no change completes it. */
	public function test_write_covered_open_sale_completes_only_on_replay(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '92.95' );
		$this->seed_rows( $order->get_id(), array( $row ) );

		// Act.
		Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( array_merge( $row, array( 'receipt' => array( 'ref' => 'r1' ) ) ) ) );
		$after_change = $this->stored_status( $order->get_id() );
		$written      = Ledger::instance()->write( $order );

		// Assert.
		$this->assertSame( 'pos-open', $after_change );
		$this->assertSame( 'completed', $this->stored_status( $order->get_id() ) );
		$this->assertSame( 1, (int) $written->completion['n'] );
	}

	/** A replay on an order whose storage already says everything saves nothing. */
	public function test_write_without_change_on_settled_order_saves_nothing(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( $this->row( '10.00' ) ) );
		$saves = 0;
		add_action(
			'woocommerce_before_order_object_save',
			function () use ( &$saves ): void {
				++$saves;
			}
		);

		// Act.
		Ledger::instance()->write( $order->get_id() );

		// Assert.
		$this->assertSame( 0, $saves );
	}

	/** An index row lost in S1's save is caught by the read-back: S1 retries, and the write acknowledges only once it is stored. */
	public function test_write_lost_payment_index_retries_s1_until_read_back(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '20.00', 'pending', array( 'capture_mode' => 'gateway' ) );
		$runs  = 0;
		$lost  = 0;
		$lose  = static function ( $sql ) use ( &$lost ) {
			if ( 0 === $lost && is_string( $sql ) && preg_match( '/^\s*INSERT/i', $sql ) && false !== strpos( $sql, "'" . Ledger::PAYMENT_ID_META_KEY . "'" ) ) {
				++$lost;
				return 'SELECT 0';
			}
			return $sql;
		};
		add_filter( 'query', $lose );

		// Act.
		try {
			Ledger::instance()->write(
				$order,
				function ( Ledger_Draft $draft ) use ( $row, &$runs ): void {
					++$runs;
					$draft->put( $row );
				}
			);
		} finally {
			remove_filter( 'query', $lose );
		}

		// Assert.
		$this->assertSame( 1, $lost );
		$this->assertSame( 2, $runs );
		$saved = $this->fresh( $order->get_id() );
		$this->assertSame( array( $row['id'] ), array_map( static fn( $meta ) => $meta->value, $saved->get_meta( Ledger::PAYMENT_ID_META_KEY, false ) ) );
		$this->assertSame( array( $row['id'] ), array_map( static fn( $meta ) => $meta->value, $saved->get_meta( Ledger::LIVE_LEG_META_KEY, false ) ) );
	}

	/** A paid status that landed without its paid date (a crash inside S2) is repaired by the replay: date, stock, completion, once. */
	public function test_write_replay_repairs_paid_status_without_date_paid_once(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$this->seed_rows( $id, array( $this->row( '92.95' ) ) );
		$paid = $this->fresh( $id );
		$paid->set_status( 'completed' );
		$this->as_if_paid_hooks_never_ran( fn() => $paid->save() );
		$undated = $this->fresh( $id );
		$undated->set_date_paid( null );
		$this->as_if_paid_hooks_never_ran( fn() => $undated->save() );
		$this->assertNull( $this->fresh( $id )->get_date_paid( 'edit' ), 'Precondition: the paid date was lost.' );
		$this->assertSame( 10, wc_get_product( $this->product->get_id() )->get_stock_quantity(), 'Precondition: stock was never reduced.' );

		// Act.
		$written = Ledger::instance()->write( $id );
		$dated   = $this->fresh( $id )->get_date_paid( 'edit' );
		$saves   = 0;
		add_action(
			'woocommerce_before_order_object_save',
			function () use ( &$saves ): void {
				++$saves;
			}
		);
		$again = Ledger::instance()->write( $id );

		// Assert.
		$this->assertNotNull( $dated );
		$this->assertSame( 1, (int) $written->completion['n'] );
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
		$this->assertSame( 0, $saves, 'A replay on a fully paid order saves nothing.' );
		$this->assertSame( $written->completion['id'], $again->completion['id'] );
		$this->assertSame( $dated->getTimestamp(), $this->fresh( $id )->get_date_paid( 'edit' )->getTimestamp() );
		$this->assertCount( 1, Completion_History::of( $this->fresh( $id ) )->entries() );
	}

	/** A paid sale whose completion never landed (a crash after S2) is completed by the next write on it. */
	public function test_write_on_paid_sale_without_completion_heals_it(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->row( '92.95' );
		$this->seed_rows( $order->get_id(), array( $row ) );
		$paid = $this->fresh( $order->get_id() );
		$paid->set_date_paid( time() );
		$paid->set_status( 'processing' );
		$this->as_if_paid_hooks_never_ran( fn() => $paid->save() );
		$this->assertNull( Completion_History::of( $this->fresh( $order->get_id() ) )->standing(), 'Precondition: no completion stands.' );
		$this->assertSame( 10, wc_get_product( $this->product->get_id() )->get_stock_quantity(), 'Precondition: stock was never reduced.' );

		// Act.
		$written = Ledger::instance()->write( $order->get_id() );

		// Assert.
		$this->assertSame( 1, (int) $written->completion['n'] );
		$this->assertSame( array( $row['id'] ), $written->completion['payment_ids'] );
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
	}

	/** A staged total that did not reach storage never reaches the paid transition; the retried command completes. */
	public function test_write_staged_total_lost_in_save_never_reaches_paid_and_retry_completes(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$this->seed_rows( $id, array( $this->row( '50.00' ) ) );
		$lower = static fn( Ledger_Draft $draft ) => $draft->order()->set_total( '50.00' );
		$keep  = static function ( $saving ) use ( $id ): void {
			if ( $saving->get_id() === $id ) {
				$saving->set_total( '92.95' ); // The staged total does not land; the rows do.
			}
		};
		add_action( 'woocommerce_before_order_object_save', $keep, PHP_INT_MAX );
		$caught = null;

		// Act.
		try {
			Ledger::instance()->write( $id, $lower );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $keep, PHP_INT_MAX );
		}
		$unpaid  = $this->stored_status( $id );
		$written = Ledger::instance()->write( $id, $lower );

		// Assert.
		$this->assertInstanceOf( Ledger_Persistence_Exception::class, $caught );
		$this->assertSame( 'rows', $caught->stage );
		$this->assertSame( 'pos-open', $unpaid );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$this->assertSame( '50.00', $written->order->get_total() );
		$this->assertSame( 1, (int) $written->completion['n'] );
	}

	/** A write whose first S1 lost an attempt fate still completes the sale it covered on the retried S1. */
	public function test_write_retried_s1_after_lost_attempt_fate_still_completes(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$row   = $this->row( '92.95' );
		$sent  = array( $row['id'] => 'sent' );
		$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, $sent );
		$order->save_meta_data();
		$lost = 0;
		$lose = static function ( $saving ) use ( $id, $sent, &$lost ): void {
			global $wpdb;
			if ( 0 !== $lost || $saving->get_id() !== $id ) {
				return;
			}
			++$lost;
			$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
			$wpdb->update(
				$hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta,
				array( 'meta_value' => maybe_serialize( $sent ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- test fixture.
				array(
					( $hpos ? 'order_id' : 'post_id' ) => $id,
					'meta_key'                         => Gateway_Submission::ATTEMPTS_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- test fixture.
				)
			);
		};
		add_action( 'woocommerce_after_order_object_save', $lose, 1 );

		// Act.
		try {
			$written = Ledger::instance()->write( $id, fn( Ledger_Draft $draft ) => $draft->put( $row ) );
		} finally {
			remove_action( 'woocommerce_after_order_object_save', $lose, 1 );
		}

		// Assert.
		$this->assertSame( 1, $lost );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$this->assertSame( 1, (int) $written->completion['n'] );
		$this->assertSame( array( $row['id'] => 'paid' ), Gateway_Submission::read_attempts( $this->fresh( $id ) ) );
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
	}

	/** A heal whose completion still does not land is the retryable completion failure, not a write without one. */
	public function test_write_heal_whose_completion_fails_throws_completion(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->seed_rows( $order->get_id(), array( $this->row( '92.95' ) ) );
		$paid = $this->fresh( $order->get_id() );
		$paid->set_date_paid( time() );
		$paid->set_status( 'processing' );
		$this->as_if_paid_hooks_never_ran( fn() => $paid->save() );
		$undo   = $this->inject( 'completion', $order->get_id() );
		$caught = null;

		// Act.
		try {
			Ledger::instance()->write( $order->get_id() );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}

		// Assert.
		$this->assertInstanceOf( Ledger_Persistence_Exception::class, $caught );
		$this->assertSame( 'completion', $caught->stage );
		$this->assertNull( Completion_History::of( $this->fresh( $order->get_id() ) )->standing() );
	}

	/** A zero-total sale closed through write() whose completion failed is healed by the next write. */
	public function test_write_zero_total_close_whose_completion_failed_heals_on_next_write(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_total( '0.00' );
		$order->save();
		$undo   = $this->inject( 'completion', $order->get_id() );
		$caught = null;
		try {
			Ledger::instance()->write( $order->get_id(), static fn() => null, Ledger::MODE_CLOSE );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$this->assertSame( 'completion', $caught ? $caught->stage : null, 'Precondition: the close was paid and its completion was lost.' );

		// Act.
		$written = Ledger::instance()->write( $order->get_id() );

		// Assert.
		$this->assertSame( 1, (int) $written->completion['n'] );
		$this->assertSame( array(), $written->completion['payment_ids'] );
	}

	/** A sent gateway sale left on-hold with its awaiting stamp completes on its covering row; the stamp goes in S3. */
	public function test_write_stamped_on_hold_sale_covered_completes_and_clears_stamp(): void {
		// Arrange.
		$id  = $this->create_stamped_on_hold_order();
		$row = $this->row( '92.95' );

		// Act.
		$written = Ledger::instance()->write( $id, fn( Ledger_Draft $draft ) => $draft->put( $row ) );

		// Assert.
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$this->assertSame( 1, (int) $written->completion['n'] );
		$this->assertNull( Gateway_Submission::read_stamp( $this->fresh( $id ) ) );
	}

	/**
	 * A stamped on-hold sale whose write failed after S1 or after S2 is completed by replaying it, once.
	 *
	 * @dataProvider stamped_failures
	 *
	 * @param string $stage The step that fails once.
	 */
	public function test_write_stamped_on_hold_sale_failing_at_stage_then_replayed_completes_once( string $stage ): void {
		// Arrange.
		$id     = $this->create_stamped_on_hold_order();
		$row    = $this->row( '92.95' );
		$undo   = $this->inject( $stage, $id );
		$caught = null;

		// Act.
		try {
			$this->pay( $id, $row );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$this->pay( $id, $row );

		// Assert.
		$this->assertSame( $stage, $caught ? $caught->stage : null );
		$saved = $this->fresh( $id );
		$this->assertSame( 'completed', $saved->get_status() );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertNull( Gateway_Submission::read_stamp( $saved ) );
	}

	/** Failures after S1 (the paid transition) and after S2 (the completion). */
	public function stamped_failures(): array {
		return array(
			'after S1' => array( 'paid' ),
			'after S2' => array( 'completion' ),
		);
	}

	/** A POS order a sent gateway submit left on-hold, awaiting the customer, with no paid date. */
	private function create_stamped_on_hold_order(): int {
		$order = $this->create_pos_order();
		$order->set_status( 'on-hold' );
		$order->save();
		$order->update_meta_data(
			Gateway_Submission::STAMP_META_KEY,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'method_id'  => 'pos_cash',
				'cashier_id' => 1,
			)
		);
		$order->save_meta_data();
		$this->assertNull( $this->fresh( $order->get_id() )->get_date_paid( 'edit' ), 'Precondition: unpaid.' );
		return $order->get_id();
	}

	/**
	 * Make one step of the next write fail.
	 *
	 * @param string $stage Step.
	 * @param int    $id    Order id.
	 * @return callable Undo.
	 */
	private function inject( string $stage, int $id ): callable {
		if ( 'lock' === $stage ) {
			$other = new Order_Lock();
			$this->assertTrue( $other->acquire( $id ) );
			return static fn() => $other->release( $id );
		}
		if ( 'rows' === $stage ) {
			return $this->lose_rows( $id, PHP_INT_MAX );
		}
		if ( 'paid' === $stage ) {
			$refuse = static fn() => array();
			add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $refuse, PHP_INT_MAX );
			return static fn() => remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $refuse, PHP_INT_MAX );
		}
		// Completion: every write of the completion history reaches nothing.
		$lose = static fn( $sql ) => is_string( $sql ) && false !== strpos( $sql, Completion_Stamp::META_KEY ) && preg_match( '/^\s*(INSERT|UPDATE)/i', $sql ) ? 'SELECT 0' : $sql;
		add_filter( 'query', $lose );
		return static fn() => remove_filter( 'query', $lose );
	}

	/**
	 * Lose the ledger meta of the next saves of an order (a save WooCommerce swallowed).
	 *
	 * @param int $id    Order id.
	 * @param int $times How many saves lose it.
	 * @return callable Undo.
	 */
	private function lose_rows( int $id, int $times ): callable {
		$lose = function ( $saving ) use ( $id, &$times ): void {
			global $wpdb;
			if ( 0 >= $times || $saving->get_id() !== $id ) {
				return;
			}
			--$times;
			$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
			$wpdb->delete(
				$hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta,
				array(
					( $hpos ? 'order_id' : 'post_id' ) => $id,
					'meta_key'                         => Ledger::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- test fixture.
				)
			);
		};
		add_action( 'woocommerce_after_order_object_save', $lose, 1 );
		return static fn() => remove_action( 'woocommerce_after_order_object_save', $lose, 1 );
	}

	/**
	 * The till's command for a tender: a replay when its row is stored (rule 5), the row otherwise.
	 *
	 * @param int   $id  Order id.
	 * @param array $row Row.
	 */
	private function pay( int $id, array $row ): void {
		$stored = in_array( $row['id'], array_column( $this->stored_rows( $id ), 'id' ), true );
		Ledger::instance()->write( $id, $stored ? null : fn( Ledger_Draft $draft ) => $draft->put( $row ) );
	}

	/**
	 * Run work with the completion and stock listeners unhooked: the paid status landed, its hooks did
	 * not (a crash inside S2, or between S2 and S3).
	 *
	 * @param callable $work Work.
	 */
	private function as_if_paid_hooks_never_ran( callable $work ): void {
		$listeners = array(
			array( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_completed' ), 15, 4 ),
			array( 'woocommerce_payment_complete', array( Completion_Stamp::class, 'on_payment_complete' ), 15, 1 ),
			array( 'woocommerce_order_status_processing', 'wc_maybe_reduce_stock_levels', 10, 1 ),
			array( 'woocommerce_payment_complete', 'wc_maybe_reduce_stock_levels', 10, 1 ),
		);
		foreach ( $listeners as $listener ) {
			remove_action( $listener[0], $listener[1], $listener[2] );
		}
		try {
			$work();
		} finally {
			foreach ( $listeners as $listener ) {
				add_action( ...$listener );
			}
		}
	}

	/**
	 * An open POS order for 92.95 holding one stocked line.
	 */
	private function create_pos_order(): \WC_Order {
		$this->product = ProductHelper::create_simple_product();
		$this->product->set_manage_stock( true );
		$this->product->set_stock_quantity( 10 );
		$this->product->save();
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();
		return $order;
	}

	/**
	 * A cash row.
	 *
	 * @param string $amount Amount.
	 * @param string $status Status.
	 * @param array  $extra  Overrides.
	 */
	private function row( string $amount, string $status = 'captured', array $extra = array() ): array {
		return array_merge(
			array(
				'id'              => wp_generate_uuid4(),
				'method_id'       => 'pos_cash',
				'kind'            => 'cash',
				'capture_mode'    => 'manual',
				'amount'          => $amount,
				'currency'        => get_woocommerce_currency(),
				'status'          => $status,
				'captured_at_gmt' => 'captured' === $status ? gmdate( 'c' ) : null,
			),
			$extra
		);
	}

	/**
	 * Store rows as they are, without a write (server-owned fields included).
	 *
	 * @param int   $id   Order id.
	 * @param array $rows Rows.
	 */
	private function seed_rows( int $id, array $rows ): void {
		$order = $this->fresh( $id );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema'   => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();
	}

	/**
	 * Give every stored row one updated_at_gmt.
	 *
	 * @param int    $id    Order id.
	 * @param string $stamp Time.
	 */
	private function seed_stamps( int $id, string $stamp ): void {
		$rows = $this->stored_rows( $id );
		foreach ( $rows as &$row ) {
			$row['updated_at_gmt'] = $stamp;
		}
		unset( $row );
		$this->seed_rows( $id, array_map( array( Ledger::class, 'to_wire' ), $rows ) );
	}

	/**
	 * The order and its meta from storage.
	 *
	 * @param int $id Order id.
	 */
	private function fresh( int $id ): \WC_Order {
		$order = wc_get_order( $id );
		$order->read_meta_data( true );
		return $order;
	}

	/**
	 * The rows in storage.
	 *
	 * @param int $id Order id.
	 */
	private function stored_rows( int $id ): array {
		return Ledger::instance()->read( $this->fresh( $id ) );
	}

	/**
	 * The status column in storage, past every cache.
	 *
	 * @param int $id Order id.
	 */
	private function stored_status( int $id ): string {
		global $wpdb;
		$status = OrderUtil::custom_orders_table_usage_is_enabled()
			? $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $id ) );
		return 'wc-' === substr( (string) $status, 0, 3 ) ? substr( (string) $status, 3 ) : (string) $status;
	}

	/**
	 * How many times this request holds the order lock.
	 *
	 * @param int $id Order id.
	 */
	private function held_count( int $id ): int {
		$held = new \ReflectionProperty( Order_Lock::class, 'held' );
		$held->setAccessible( true );
		$locks = $held->getValue( Order_Lock::instance() );
		return (int) ( $locks[ sprintf( 'wcpos_order_%d_%d', $id, get_current_blog_id() ) ]['count'] ?? 0 );
	}
}
