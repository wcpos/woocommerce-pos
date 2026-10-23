<?php
/**
 * The ledger write as an ordered unit of work (ADR 0040, amended 2026-10-22).
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Command_Log;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Money;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Command;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Payments\Contract\Payments_Sweeper;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Save_Hooks;
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

	/** A zero-total close stores its command owing the completion before it is paid, so a lost completion heals. */
	public function test_write_zero_total_close_owes_its_completion_and_heals_it(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_total( '0.00' );
		$order->save();
		$command = wp_generate_uuid4();
		$undo    = $this->inject( 'completion', $order->get_id() );
		$caught  = null;
		try {
			Ledger::instance()->write( $order->get_id(), static fn(): bool => true, Ledger::MODE_CLOSE, new Money_Command( $command, 'sha256:close', 'close' ) );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$this->assertSame( 'completion', $caught ? $caught->stage : null, 'Precondition: paid, completion lost.' );
		$this->assertSame( Command_Log::OWED, Command_Log::find( $this->fresh( $order->get_id() ), array(), $command )['phase'] ?? null, 'S1 stored the command owing the completion.' );

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
			'captured amount rises untipped' => array(
				array( 'method_id' => 'pos_card' ),
				$edit(
					array(
						'amount' => '11.00',
						'tip' => '0.50',
					)
				),
			),
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

	/** A covered open sale stays open under a write that changes a row (rule 3); the replay of a command owing it completes it. */
	public function test_write_covered_open_sale_completes_only_on_replay(): void {
		// Arrange: the covering row's command stored its rows and still owes the paid transition.
		$order = $this->create_pos_order();
		$entry = array_merge(
			Command_Log::entry( 'sha256:cash', 'record' ),
			array(
				'phase'     => Command_Log::OWED,
				'owed_at_n' => 0,
			)
		);
		$row   = Command_Log::with_on_row( $this->row( '92.95' ), wp_generate_uuid4(), $entry );
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
		$this->assertSame( array( $row['id'] ), array_values( array_map( static fn( $meta ) => $meta->value, $saved->get_meta( Ledger::PAYMENT_ID_META_KEY, false ) ) ) );
		$this->assertSame( array( $row['id'] ), array_values( array_map( static fn( $meta ) => $meta->value, $saved->get_meta( Ledger::LIVE_LEG_META_KEY, false ) ) ) );
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
			Ledger::instance()->write( $order->get_id(), static fn(): bool => true, Ledger::MODE_CLOSE, new Money_Command( wp_generate_uuid4(), 'sha256:close', 'close' ) );
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

	/**
	 * The order's notes, joined.
	 *
	 * @param int $id Order id.
	 */
	private function notes( int $id ): string {
		return implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $id ) ), 'content' ) );
	}

	/**
	 * Park a confirmation from inside the order's next save, once (a webhook landing while the arrival is stored).
	 *
	 * @param int    $id         Order id.
	 * @param string $payment_id Payment id.
	 * @param array  $patch      Confirmation.
	 * @return callable Undo.
	 */
	private function park_once( int $id, string $payment_id, array $patch ): callable {
		$parked = false;
		$park   = static function ( $saving ) use ( $id, $payment_id, $patch, &$parked ): void {
			if ( ! $parked && $saving->get_id() === $id ) {
				$parked = true;
				wcpos_settle_payment( $payment_id, $patch );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $park, 1 );
		return static fn() => remove_action( 'woocommerce_before_order_object_save', $park, 1 );
	}

	/**
	 * Store an awaiting-customer stamp as a sent submit leaves it.
	 *
	 * @param int $id Order id.
	 */
	private function seed_stamp( int $id ): void {
		$order = $this->fresh( $id );
		$order->update_meta_data(
			Gateway_Submission::STAMP_META_KEY,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'method_id'  => 'pos_cash',
				'cashier_id' => 1,
			)
		);
		$order->save_meta_data();
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

	/** Is writing only while a write on that order runs. */
	public function test_is_writing_is_true_only_inside_write_on_that_order(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$inside = null;
		$other  = null;

		// Act.
		Ledger::instance()->write(
			$order,
			function () use ( $order, &$inside, &$other ): void {
				$inside = Ledger::is_writing( $order->get_id() );
				$other  = Ledger::is_writing( $order->get_id() + 1 );
			}
		);

		// Assert.
		$this->assertTrue( $inside );
		$this->assertFalse( $other );
		$this->assertFalse( Ledger::is_writing( $order->get_id() ) );
	}

	/** Work deferred inside a write's own paid hooks runs once that write returns: outside every hook and every write. */
	public function test_defer_from_a_paid_hook_runs_after_the_outermost_write(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$ran   = array();
		$defer = static function () use ( $id, &$ran ): void {
			Ledger::defer(
				$id,
				static function () use ( $id, &$ran ): void {
					$ran[] = array(
						'hooks'   => array_values( array_filter( (array) $GLOBALS['wp_current_filter'], array( Save_Hooks::class, 'contains' ) ) ),
						'writing' => Ledger::is_writing( $id ),
					);
					$late = wc_get_order( $id );
					$late->update_meta_data( '_wcpos_test_deferred', 'ran' );
					$late->save_meta_data();
				},
				'the test job'
			);
		};
		add_action( 'woocommerce_order_status_changed', $defer );

		// Act.
		$written = Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( $this->row( '92.95' ) ) );

		// Assert.
		$this->assertSame(
			array(
				array(
					'hooks'   => array(),
					'writing' => false,
				),
			),
			$ran
		);
		$this->assertSame( 'ran', $written->order->get_meta( '_wcpos_test_deferred' ), 'The answer is read after the deferred work.' );
	}

	/** A nested write on the order leaves the queue to the outer write. */
	public function test_defer_inside_a_nested_write_waits_for_the_outer_write(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$id     = $order->get_id();
		$ran    = 0;
		$inside = null;

		// Act.
		Ledger::instance()->write(
			$order,
			function () use ( $id, &$ran, &$inside ): void {
				Ledger::defer(
					$id,
					static function () use ( &$ran ): void {
						++$ran;
					},
					'the test job'
				);
				Ledger::instance()->write( $id );
				$inside = $ran;
			}
		);

		// Assert.
		$this->assertSame( 0, $inside );
		$this->assertSame( 1, $ran );
	}

	/** Order-pay's successful result runs that order's deferred work, and only that order's. */
	public function test_defer_flushes_at_payment_successful_result_for_that_order_only(): void {
		// Arrange.
		$paying = $this->create_pos_order()->get_id();
		$other  = $this->create_pos_order()->get_id();
		$ran    = array();
		foreach ( array( $paying, $other ) as $id ) {
			Ledger::defer(
				$id,
				static function () use ( $id, &$ran ): void {
					$ran[] = $id;
				},
				'the test job'
			);
		}

		// Act.
		$result = apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), $paying ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.

		// Assert.
		$this->assertSame( array( 'result' => 'success' ), $result );
		$this->assertSame( array( $paying ), $ran );
		Ledger::flush_all();
		$this->assertSame( array( $paying, $other ), $ran );
	}

	/** Work deferred outside any write waits for shutdown, which runs every order's queue. */
	public function test_defer_outside_a_write_runs_at_shutdown(): void {
		// Arrange.
		$first  = $this->create_pos_order()->get_id();
		$second = $this->create_pos_order()->get_id();
		$ran    = array();

		// Act.
		foreach ( array( $first, $second ) as $id ) {
			Ledger::defer(
				$id,
				static function () use ( $id, &$ran ): void {
					$ran[] = $id;
				},
				'the test job'
			);
		}
		$before = $ran;
		Ledger::flush_all();

		// Assert.
		$this->assertNotFalse( has_action( 'shutdown', array( Ledger::class, 'flush_all' ) ) );
		$this->assertSame( array(), $before );
		$this->assertSame( array( $first, $second ), $ran );
	}

	/** A deferred job that throws is logged and noted on the order under its label; the next job still runs. */
	public function test_defer_throwing_job_is_logged_and_noted_and_the_rest_still_run(): void {
		// Arrange.
		$id     = $this->create_pos_order()->get_id();
		$ran    = false;
		$errors = array();
		$log    = static function ( $enabled, $message ) use ( &$errors ) {
			if ( 'error' === \WCPOS\WooCommercePOS\Logger::$log_level ) {
				$errors[] = $message;
			}
			return false;
		};
		add_filter( 'wcpos_logging', $log, 10, 2 );
		Ledger::defer(
			$id,
			static function (): void {
				throw new \RuntimeException( 'job failed' );
			},
			'Recording the test payment'
		);
		Ledger::defer(
			$id,
			static function () use ( &$ran ): void {
				$ran = true;
			},
			'the next job'
		);

		// Act.
		Ledger::flush_all();
		remove_filter( 'wcpos_logging', $log, 10 );

		// Assert.
		$this->assertTrue( $ran );
		$this->assertCount( 1, array_filter( $errors, static fn( $message ) => false !== strpos( $message, 'Recording the test payment' ) && false !== strpos( $message, 'job failed' ) ) );
		$notes = array_column( wc_get_order_notes( array( 'order_id' => $id ) ), 'content' );
		$this->assertContains( 'WCPOS: Recording the test payment did not complete; reconcile manually.', $notes );
	}

	/**
	 * H2: a ledger meta write inside a status hook writes the meta only. On HPOS WooCommerce re-saves the whole
	 * order after a meta change whenever date_modified is older than now; WooCommerce's own saves inside the
	 * status hooks move it to now, so the test stands in for the clock having ticked past it since.
	 */
	public function test_completion_stamped_inside_a_status_hook_runs_no_nested_order_save(): void {
		// Arrange: a till sale its cash covers.
		$order = $this->create_pos_order();
		$this->seed_rows( $order->get_id(), array( $this->row( '92.95' ) ) );
		$nested = array();
		$count  = static function ( $saving ) use ( &$nested ): void {
			if ( doing_action( 'woocommerce_order_status_changed' ) ) {
				$nested[] = $saving->get_id();
			}
		};
		add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_true' );
		add_action( 'woocommerce_before_order_object_save', $count );

		// Act.
		$this->fresh( $order->get_id() )->update_status( 'processing' );
		remove_action( 'woocommerce_before_order_object_save', $count );
		remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_true' );

		// Assert.
		$this->assertNotNull( Completion_History::of( $this->fresh( $order->get_id() ) )->standing(), 'Precondition: the status hook stamped the completion.' );
		$this->assertSame( array(), $nested );
	}

	/** The completion of a write is its own step (S3): no listener stamps it inside the paid hooks. */
	public function test_write_completion_is_stamped_after_the_paid_hooks_return(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$seen  = array();
		$look  = function () use ( $order, &$seen ): void {
			$seen[] = Completion_History::of( $this->fresh( $order->get_id() ) )->entries();
		};
		add_action( 'woocommerce_order_status_changed', $look, 99 );
		add_action( 'woocommerce_payment_complete', $look, 99 );

		// Act.
		$written = Ledger::instance()->write( $order, fn( Ledger_Draft $draft ) => $draft->put( $this->row( '92.95' ) ) );

		// Assert.
		$this->assertSame( array( array(), array() ), $seen );
		$this->assertSame( 1, (int) $written->completion['n'] );
	}

	/**
	 * `record` failing after S1, after S2 or after S3 (its answer lost), then replayed with the same
	 * command: one completion, one fiscal sale, stock reduced once, the row of a single run.
	 *
	 * @dataProvider record_failures
	 *
	 * @param string|null $stage The step that fails once; null when only the answer is lost.
	 */
	public function test_record_failing_at_stage_then_replayed_lands_one_completion( ?string $stage ): void {
		// Arrange.
		global $wpdb;
		$order   = $this->create_pos_order();
		$id      = $order->get_id();
		$input   = $this->tender( '92.95' );
		$command = $this->record_command( $input );
		$undo    = null === $stage ? static fn() => null : $this->inject( $stage, $id );
		$caught  = null;

		// Act.
		try {
			Ledger::instance()->record( wc_get_order( $id ), $input, array(), $command );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$wpdb->last_error = '';
		$replayed = $this->replay_record( $id, $input );

		// Assert.
		$this->assertSame( $stage, $caught ? $caught->stage : null );
		$saved = $this->fresh( $id );
		$this->assertSame( 'completed', $saved->get_status() );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertSame( array( $input['id'] ), Completion_History::of( $saved )->standing()['payment_ids'] );
		$this->assertSame(
			1,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $id,
					'type' => 'sale',
				)
			)
		);
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
		$rows = $this->stored_rows( $id );
		$this->assertSame( array( $input['id'] ), array_column( $rows, 'id' ) );
		$this->assertSame( 'captured', $rows[0]['status'] );
		$this->assertSame( $rows[0], $replayed, 'The replay answers the row as stored.' );
		$this->assertArrayNotHasKey( 'phase', $rows[0]['commands'][ $input['id'] ], 'S3 advanced the command past its rows.' );
	}

	/** Failures after S1 (the paid transition fails), after S2 (the completion fails) and after S3. */
	public function record_failures(): array {
		return array(
			'after S1' => array( 'paid' ),
			'after S2' => array( 'completion' ),
			'after S3' => array( null ),
		);
	}

	/**
	 * `capture` (one `apply_result()` write) failing after S1, after S2 or after S3 (its answer lost), then replayed
	 * with the same command: the provider asked once, one completion naming the row, one fiscal sale, stock reduced once.
	 *
	 * @dataProvider record_failures
	 *
	 * @param string|null $stage The step that fails once; null when only the answer is lost.
	 */
	public function test_capture_failing_at_stage_then_replayed_lands_one_completion( ?string $stage ): void {
		// Arrange: a pending provider leg for the whole total.
		global $wpdb;
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$row   = $this->row( '92.95', 'pending', array( 'capture_mode' => 'capture_test' ) );
		Capture_Mode_Registry::instance()->register( 'capture_test', Capture_Test_Handler::class );
		Capture_Test_Handler::$captures = 0;
		Ledger::instance()->write( $id, static fn( Ledger_Draft $draft ) => $draft->put( $row ), Ledger::MODE_HOLD );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:capture', 'capture', $row['id'] );
		$capture = static fn( Money_Command $c ) => Ledger::instance()->capture( wc_get_order( $id ), $row['id'], array(), $c );
		$undo    = null === $stage ? static fn() => null : $this->inject( $stage, $id );
		$caught  = null;

		// Act.
		try {
			$capture( $command );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$wpdb->last_error = '';
		$replayed = Command_Log::run( wc_get_order( $id ), $command, $capture );

		// Assert.
		$this->assertSame( $stage, $caught ? $caught->stage : null );
		$this->assertSame( 1, Capture_Test_Handler::$captures );
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
		$this->assertSame( 'captured', $rows[0]['status'] );
		$this->assertSame( 'captured', $replayed['payment']['status'] );
		$this->assertArrayNotHasKey( 'phase', $rows[0]['commands'][ $command->id() ], 'S3 advanced the command past its rows.' );
	}

	/**
	 * A refund that returns the money a reopened sale owed back completes it again; its settle write failing after
	 * S1 or after S2, the same command replayed completes it once more and no more (rules 1 and 8). Nothing escapes.
	 *
	 * @dataProvider record_failures
	 *
	 * @param string|null $stage The step that fails once; null when only the answer is lost.
	 */
	public function test_refund_returning_money_owed_back_failing_at_stage_then_replayed_completes_once( ?string $stage ): void {
		// Arrange: a cash sale paid and completed, reopened at a total 20.00 lower, and a 20.00 refund of the excess.
		global $wpdb;
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$row   = $this->row( '92.95' );
		$this->pay( $id, $row );
		$reopened = $this->fresh( $id );
		$reopened->set_status( 'pos-open' );
		$reopened->set_total( '72.95' );
		$reopened->save();
		$stock  = wc_get_product( $this->product->get_id() )->get_stock_quantity();
		$refund = wc_create_refund(
			array(
				'order_id'       => $id,
				'amount'         => '20.00',
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:refund', 'refund', $row['id'], $refund->get_id() );
		$allot   = static fn( Money_Command $c ) => Ledger::instance()->refund( wc_get_order( $id ), $row['id'], $refund->get_id(), '20.00', $c );
		$undo    = null === $stage ? static fn() => null : $this->inject( $stage, $id );

		// Act.
		try {
			$first = $allot( $command );
		} finally {
			$undo();
		}
		$wpdb->last_error = '';
		$replayed = Command_Log::run( wc_get_order( $id ), $command, $allot );

		// Assert.
		$this->assertSame( 'succeeded', $first['refunds'][0]['status'], 'The settled allocation is the answer, never a throw.' );
		$this->assertSame( 'succeeded', $replayed['payment']['refunds'][0]['status'] );
		$saved = $this->fresh( $id );
		$this->assertSame( 'completed', $saved->get_status() );
		$this->assertSame( array( 1, 2 ), array_column( Completion_History::of( $saved )->entries(), 'n' ) );
		$this->assertSame( 2, (int) Completion_History::of( $saved )->standing()['n'] );
		$this->assertSame(
			2,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $id,
					'type'     => 'sale',
				)
			)
		);
		$this->assertSame( $stock, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
		$stored = $this->stored_rows( $id )[0];
		$this->assertCount( 1, $stored['refunds'] );
		$this->assertSame( '20.00', $stored['refunded_amount'] );
		$this->assertArrayNotHasKey( 'phase', $stored['commands'][ $command->id() ] );
	}

	/** A `record` whose S2 failed leaves its command entry saying `rows`: storage knows the paid transition is owed. */
	public function test_record_failing_after_rows_leaves_its_command_owing_the_paid_transition(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$input   = $this->tender( '92.95' );
		$undo    = $this->inject( 'paid', $order->get_id() );

		// Act.
		try {
			Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );
		} catch ( Ledger_Persistence_Exception $error ) {
			unset( $error );
		} finally {
			$undo();
		}

		// Assert.
		$rows = $this->stored_rows( $order->get_id() );
		$this->assertSame( 'rows', $rows[0]['commands'][ $input['id'] ]['phase'] ?? null );
		$this->assertSame( 'pos-open', $this->stored_status( $order->get_id() ) );
	}

	/** A partial `record` owes no paid transition: its entry carries no `rows` phase. */
	public function test_record_partial_tender_marks_no_owed_phase(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->tender( '20.00' );

		// Act.
		Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );

		// Assert.
		$rows = $this->stored_rows( $order->get_id() );
		$this->assertArrayNotHasKey( 'phase', $rows[0]['commands'][ $input['id'] ] );
		$this->assertSame( 'pos-partial', $this->stored_status( $order->get_id() ) );
	}

	/** `close` on a covered open sale completes it once, its command entry naming the completion (S3 carries the ref). */
	public function test_close_covered_sale_completes_with_the_close_ref(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->seed_rows( $order->get_id(), array( $this->row( '92.95' ) ) );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:close', 'close' );

		// Act.
		$closed = Ledger::instance()->close( wc_get_order( $order->get_id() ), $command );

		// Assert.
		$saved = $this->fresh( $order->get_id() );
		$entry = Command_Log::find( $saved, Ledger::instance()->read( $saved ), $command->id() );
		$this->assertSame( 1, $closed['completion']['n'] );
		$this->assertSame( Completion_History::of( $saved )->standing()['id'], $closed['completion']['id'] );
		$this->assertSame( $closed['completion']['id'], $entry['ref'] );
		$this->assertArrayNotHasKey( 'phase', $entry );
		$this->assertSame(
			1,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $order->get_id(),
					'type' => 'sale',
				)
			)
		);
	}

	/** A `close` whose paid transition failed is completed by the same command again, naming its completion. */
	public function test_close_failing_after_rows_then_retried_completes_once_with_the_ref(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$this->seed_rows( $id, array( $this->row( '92.95' ) ) );
		$command = new Money_Command( wp_generate_uuid4(), 'sha256:close', 'close' );
		$undo    = $this->inject( 'paid', $id );
		$caught  = null;
		try {
			Ledger::instance()->close( wc_get_order( $id ), $command );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			$undo();
		}
		$owed = Command_Log::find( $this->fresh( $id ), $this->stored_rows( $id ), $command->id() );

		// Act.
		$closed = Ledger::instance()->close( wc_get_order( $id ), $command );

		// Assert.
		$this->assertSame( 'paid', $caught ? $caught->stage : null );
		$this->assertSame( 'rows', $owed['phase'] ?? null );
		$saved = $this->fresh( $id );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertSame( $closed['completion']['id'], Command_Log::find( $saved, $this->stored_rows( $id ), $command->id() )['ref'] );
	}

	/** The sweeper on an order whose write stopped after S1 runs the paid transition, then the completion, once. */
	public function test_sweeper_on_s1_only_order_runs_paid_then_completion_once(): void {
		// Arrange: the covering row stored with its command owing S2, beside a live leg the sweep selects the order by.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$cash  = Command_Log::with_on_row( $this->row( '92.95' ), wp_generate_uuid4(), Command_Log::entry( 'sha256:cash', 'record' ) );
		$live  = $this->row( '1.00', 'pending', array( 'capture_mode' => 'sweep_none' ) );
		$undo  = $this->inject( 'paid', $id );
		try {
			Ledger::instance()->write(
				$id,
				static function ( Ledger_Draft $draft ) use ( $cash, $live ): void {
					$draft->put( $cash );
					$draft->put( $live );
				}
			);
		} catch ( Ledger_Persistence_Exception $error ) {
			unset( $error );
		} finally {
			$undo();
		}
		$this->assertSame( 'pos-open', $this->stored_status( $id ), 'Precondition: S1 only.' );

		// Act.
		( new Payments_Sweeper() )->run();
		( new Payments_Sweeper() )->run();

		// Assert.
		$saved = $this->fresh( $id );
		$this->assertSame( 'completed', $saved->get_status() );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertSame(
			1,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $id,
					'type' => 'sale',
				)
			)
		);
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
	}

	/** Rule 3: a write with no change on a reopened sale its rows still cover runs no paid transition. */
	public function test_write_without_change_on_reopened_covered_sale_runs_no_paid_transition(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = $this->tender( '92.95' );
		Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );
		$reopened = $this->fresh( $id );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$this->assertNull( Completion_History::of( $this->fresh( $id ) )->standing(), 'Precondition: reopened.' );
		$paid = 0;
		add_action(
			'woocommerce_pre_payment_complete',
			function () use ( &$paid ): void {
				++$paid;
			}
		);

		// Act.
		$written = Ledger::instance()->write( $id );

		// Assert.
		$this->assertSame( 0, $paid );
		$this->assertSame( 'pos-open', $this->stored_status( $id ) );
		$this->assertNull( $written->completion );
		$this->assertCount( 1, Completion_History::of( $this->fresh( $id ) )->entries() );
	}

	/**
	 * A command owing S2 whose sale then completed by another path is settled: after a reopen its rows
	 * still cover, a write with no change runs no paid transition (rule 3), whatever the clock says.
	 *
	 * @dataProvider other_completions
	 *
	 * @param string $path How the sale completed: the ledger's derive() inside a save, or a status set outside the ledger.
	 */
	public function test_write_without_change_after_owed_sale_completed_elsewhere_runs_no_paid_transition( string $path ): void {
		// Arrange: a record that stored its rows and owes S2.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = $this->tender( '92.95' );
		$undo  = $this->inject( 'paid', $id );
		try {
			Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );
		} catch ( Ledger_Persistence_Exception $error ) {
			unset( $error );
		} finally {
			$undo();
		}
		$this->assertSame( 'rows', $this->stored_rows( $id )[0]['commands'][ $input['id'] ]['phase'] ?? null, 'Precondition: owed.' );
		$elsewhere = $this->fresh( $id );
		if ( 'derive' === $path ) {
			Ledger::instance()->save( $elsewhere, $this->stored_rows( $id ) );
		} else {
			$elsewhere->set_status( 'completed' );
			$elsewhere->save();
		}
		$this->assertSame( 1, (int) Completion_History::of( $this->fresh( $id ) )->standing()['n'], 'Precondition: completed elsewhere.' );
		$reopened = $this->fresh( $id );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$paid = 0;
		add_action(
			'woocommerce_pre_payment_complete',
			function () use ( &$paid ): void {
				++$paid;
			}
		);

		// Act.
		$written = Ledger::instance()->write( $id );

		// Assert.
		$this->assertSame( 0, $paid );
		$this->assertSame( 'pos-open', $this->stored_status( $id ) );
		$this->assertNull( $written->completion );
		$this->assertCount( 1, Completion_History::of( $this->fresh( $id ) )->entries() );
	}

	/** Completions that do not run through write(). */
	public function other_completions(): array {
		return array(
			'derive() inside a ledger save' => array( 'derive' ),
			'a status set outside the ledger' => array( 'status' ),
		);
	}

	/** A sale the ledger's derive() completes advances the commands owing it, as S3 does. */
	public function test_derive_completing_an_owed_sale_advances_its_commands(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = $this->tender( '92.95' );
		$undo  = $this->inject( 'paid', $id );
		try {
			Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );
		} catch ( Ledger_Persistence_Exception $error ) {
			unset( $error );
		} finally {
			$undo();
		}

		// Act.
		Ledger::instance()->save( $this->fresh( $id ), $this->stored_rows( $id ) );

		// Assert.
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$this->assertArrayNotHasKey( 'phase', $this->stored_rows( $id )[0]['commands'][ $input['id'] ] );
	}

	/** An authorized offline record whose parked webhook captured the balance completes the sale once; its replay is a no-op. */
	public function test_record_authorized_with_parked_capture_covering_the_balance_completes_once(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = array_merge(
			$this->tender( '92.95' ),
			array(
				'method_id' => 'pos_card',
				'status' => 'authorized',
			)
		);
		$this->assertTrue(
			wcpos_settle_payment(
				$input['id'],
				array(
					'status' => 'captured',
					'event_id' => 'evt_parked',
				)
			)
		);

		// Act.
		$row    = Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		$replay = Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );

		// Assert.
		$this->assertIsArray( $row );
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$history = Completion_History::of( $this->fresh( $id ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( 1, (int) $history[0]['n'] );
		$this->assertSame( $row, $replay );
		$this->assertArrayNotHasKey( $input['id'], (array) get_option( 'wcpos_pending_settlements', array() ) );
	}

	/** A parked on-reader capture above the recorded leg lands its Tip fee and the raised total in S1, then completes. */
	public function test_record_with_parked_tipped_capture_stores_fee_and_total_with_the_rows(): void {
		// Arrange: the card leg tips on the reader; the confirmation for 100.00 parked before the 92.95 arrival.
		Capture_Mode_Registry::instance()->register( 'tip_test', Tip_Test_Handler::class );
		$mode = static fn( $mode, $gateway ) => 'pos_card' === $gateway->id ? 'tip_test' : $mode;
		add_filter( 'wcpos_payment_method_capture_mode', $mode, 10, 2 );
		$order = $this->priced_pos_order();
		$id    = $order->get_id();
		$input = array_merge(
			$this->tender( '92.95' ),
			array(
				'method_id' => 'pos_card',
				'status' => 'authorized',
			)
		);
		wcpos_settle_payment(
			$input['id'],
			array(
				'status' => 'captured',
				'amount' => '100.00',
			)
		);

		// Act.
		try {
			$row = Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $mode, 10 );
		}

		// Assert.
		$saved = new \WC_Order( $id );
		$fees  = array_values( $saved->get_fees() );
		$this->assertCount( 1, $fees );
		$this->assertSame( '7.05', $fees[0]->get_total() );
		$this->assertSame( '100.00', $saved->get_total() );
		$this->assertSame( '100.00', $row['amount'] );
		$this->assertSame( '7.05', $row['tip'] );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$this->assertCount( 1, Completion_History::of( $this->fresh( $id ) )->entries() );
	}

	/** The fee insert is guarded, the totals are always recalculated: an S1 that stored the fee and row but lost the total retries to the tipped total. */
	public function test_record_tipped_drain_whose_total_was_lost_retries_to_the_tipped_total(): void {
		// Arrange.
		Capture_Mode_Registry::instance()->register( 'tip_test', Tip_Test_Handler::class );
		$mode = static fn( $mode, $gateway ) => 'pos_card' === $gateway->id ? 'tip_test' : $mode;
		add_filter( 'wcpos_payment_method_capture_mode', $mode, 10, 2 );
		$order = $this->priced_pos_order();
		$id    = $order->get_id();
		$input = array_merge(
			$this->tender( '92.95' ),
			array(
				'method_id' => 'pos_card',
				'status' => 'authorized',
			)
		);
		wcpos_settle_payment(
			$input['id'],
			array(
				'status' => 'captured',
				'amount' => '100.00',
			)
		);
		$lost = 0;
		$keep = static function ( $saving ) use ( $id, &$lost ): void {
			// The rows save (S1): the one carrying the tipped row. The fee and the row land; the tipped total does not.
			if ( 0 === $lost && $saving->get_id() === $id && false !== strpos( (string) $saving->get_meta( Ledger::META_KEY ), '"tip":"7.05"' ) ) {
				++$lost;
				$saving->set_total( '92.95' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $keep, PHP_INT_MAX );

		// Act.
		try {
			Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $keep, PHP_INT_MAX );
			remove_filter( 'wcpos_payment_method_capture_mode', $mode, 10 );
		}

		// Assert.
		$saved = new \WC_Order( $id );
		$this->assertSame( 1, $lost );
		$this->assertCount( 1, $saved->get_fees() );
		$this->assertSame( '100.00', $saved->get_total() );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$this->assertCount( 1, Completion_History::of( $this->fresh( $id ) )->entries() );
	}

	/** A late-drained tipped capture carrying an event id, whose first S1 lost the total, still retries to the tipped total. */
	public function test_record_late_tipped_drain_with_event_whose_total_was_lost_retries_to_the_tipped_total(): void {
		// Arrange.
		Capture_Mode_Registry::instance()->register( 'tip_test', Tip_Test_Handler::class );
		$mode = static fn( $mode, $gateway ) => 'pos_card' === $gateway->id ? 'tip_test' : $mode;
		add_filter( 'wcpos_payment_method_capture_mode', $mode, 10, 2 );
		$order = $this->priced_pos_order();
		$id    = $order->get_id();
		$input = array_merge(
			$this->tender( '92.95' ),
			array(
				'method_id' => 'pos_card',
				'status' => 'authorized',
			)
		);
		$park  = $this->park_once(
			$id,
			$input['id'],
			array(
				'status'   => 'captured',
				'amount'   => '100.00',
				'event_id' => 'evt_tip',
			)
		);
		$lost  = 0;
		$keep  = static function ( $saving ) use ( $id, &$lost ): void {
			// The rows save (S1): the one carrying the tipped row. The fee and the row land; the tipped total does not.
			if ( 0 === $lost && $saving->get_id() === $id && false !== strpos( (string) $saving->get_meta( Ledger::META_KEY ), '"tip":"7.05"' ) ) {
				++$lost;
				$saving->set_total( '92.95' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $keep, PHP_INT_MAX );

		// Act.
		try {
			Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} finally {
			$park();
			remove_action( 'woocommerce_before_order_object_save', $keep, PHP_INT_MAX );
			remove_filter( 'wcpos_payment_method_capture_mode', $mode, 10 );
		}

		// Assert.
		$saved = new \WC_Order( $id );
		$this->assertSame( 1, $lost );
		$this->assertCount( 1, $saved->get_fees() );
		$this->assertSame( '100.00', $saved->get_total() );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
		$this->assertCount( 1, Completion_History::of( $this->fresh( $id ) )->entries() );
	}

	/** A debt a completion outside the ledger settled does not let the sweep complete the reopened sale again. */
	public function test_sweeper_on_reopened_sale_whose_debt_was_settled_elsewhere_completes_nothing(): void {
		// Arrange: a record stops after S1; the sale completes outside the ledger; the merchant reopens it.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = $this->tender( '92.95' );
		$undo  = $this->inject( 'paid', $id );
		try {
			Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );
		} catch ( Ledger_Persistence_Exception $error ) {
			unset( $error );
		} finally {
			$undo();
		}
		$elsewhere = $this->fresh( $id );
		$elsewhere->set_status( 'completed' );
		$elsewhere->save();
		$reopened = $this->fresh( $id );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$sales = ( new Fiscal_Record_Store() )->count(
			array(
				'order_id' => $id,
				'type' => 'sale',
			)
		);

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$saved = $this->fresh( $id );
		$this->assertSame( 'pos-open', $this->stored_status( $id ) );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertSame(
			$sales,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $id,
					'type' => 'sale',
				)
			)
		);
		$this->assertArrayNotHasKey( 'phase', $this->stored_rows( $id )[0]['commands'][ $input['id'] ] );
		$this->assertSame( array(), $saved->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );
	}

	/** WooCommerce computes a taxed tip's totals: three taxed lines rounded at subtotal plus a taxable tip leave no balance. */
	public function test_record_taxed_tip_rounded_at_subtotal_leaves_no_balance(): void {
		// Arrange: 10% tax rounded at subtotal over three 3.33 lines (each line's tax is 0.333).
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
		Capture_Mode_Registry::instance()->register( 'tip_test', Tip_Test_Handler::class );
		$mode = static fn( $mode, $gateway ) => 'pos_card' === $gateway->id ? 'tip_test' : $mode;
		add_filter( 'wcpos_payment_method_capture_mode', $mode, 10, 2 );
		add_filter( 'wcpos_payment_tip_fee_taxable', '__return_true' );
		$order = wc_create_order();
		for ( $i = 0; $i < 3; $i++ ) {
			$product = ProductHelper::create_simple_product();
			$product->set_regular_price( '3.33' );
			$product->save();
			$order->add_product( $product, 1 );
		}
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->calculate_totals( true );
		$id    = $order->get_id();
		$total = Money::minor( wc_get_order( $id )->get_total() );
		$input = array_merge(
			$this->tender( Money::format( $total ) ),
			array(
				'method_id' => 'pos_card',
				'status' => 'authorized',
			)
		);
		wcpos_settle_payment(
			$input['id'],
			array(
				'status' => 'captured',
				'amount' => Money::format( $total + 110 ),
			)
		);

		// Act.
		try {
			$row = Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} finally {
			remove_filter( 'wcpos_payment_tip_fee_taxable', '__return_true' );
			remove_filter( 'wcpos_payment_method_capture_mode', $mode, 10 );
		}

		// Assert: the stored total is WooCommerce's own figure and the tipped capture covers it exactly.
		$saved  = new \WC_Order( $id );
		$stored = $saved->get_total();
		$this->assertSame( $stored, ( new \WC_Order( $id ) )->calculate_totals( false ) );
		$this->assertSame( Money::minor( $row['amount'] ), Money::minor( $stored ) );
		$this->assertSame( '0.00', Ledger::instance()->summary( $saved )['balance'] );
	}

	/** A tipped confirmation parked while a captured arrival is stored raises its amount by the verified tip on the drain. */
	public function test_record_captured_with_tipped_capture_parked_during_its_rows_save_takes_the_tip(): void {
		// Arrange.
		Capture_Mode_Registry::instance()->register( 'tip_test', Tip_Test_Handler::class );
		$mode = static fn( $mode, $gateway ) => 'pos_card' === $gateway->id ? 'tip_test' : $mode;
		add_filter( 'wcpos_payment_method_capture_mode', $mode, 10, 2 );
		$order = $this->priced_pos_order();
		$id    = $order->get_id();
		$input = array_merge( $this->tender( '92.95' ), array( 'method_id' => 'pos_card' ) );
		$park  = $this->park_once(
			$id,
			$input['id'],
			array(
				'status' => 'captured',
				'amount' => '100.00',
			)
		);

		// Act.
		try {
			$row = Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} finally {
			$park();
			remove_filter( 'wcpos_payment_method_capture_mode', $mode, 10 );
		}

		// Assert.
		$saved = new \WC_Order( $id );
		$this->assertSame( '100.00', $row['amount'] );
		$this->assertSame( '7.05', $row['tip'] );
		$this->assertCount( 1, $saved->get_fees() );
		$this->assertSame( '100.00', $saved->get_total() );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
	}

	/**
	 * An authorized arrival whose covering capture parked during its save, drained, then stopped before
	 * the paid transition: the command owes it, the sweep still sees the order, and the replay completes once.
	 */
	public function test_record_authorized_drained_capture_stopping_before_paid_owes_and_replays_once(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = array_merge(
			$this->tender( '92.95' ),
			array(
				'method_id' => 'pos_card',
				'status' => 'authorized',
			)
		);
		$park  = $this->park_once( $id, $input['id'], array( 'status' => 'captured' ) );
		$undo  = $this->inject( 'paid', $id );
		try {
			Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} catch ( Ledger_Persistence_Exception $error ) {
			unset( $error );
		} finally {
			$park();
			$undo();
		}
		$rows  = $this->stored_rows( $id );
		$entry = $rows[0]['commands'][ $input['id'] ];
		$live  = array_map( static fn( $meta ) => $meta->value, $this->fresh( $id )->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );

		// Act.
		$this->replay_record( $id, $input );

		// Assert.
		$this->assertSame( 'captured', $rows[0]['status'] );
		$this->assertSame( Command_Log::OWED, $entry['phase'] ?? null );
		$this->assertSame( 0, $entry['owed_at_n'] ?? null );
		$this->assertSame( array( $input['id'] ), array_values( $live ), 'The sweep still selects the order.' );
		$saved = $this->fresh( $id );
		$this->assertSame( 'completed', $saved->get_status() );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertSame( array(), $saved->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );
	}

	/** Counted money leaves only through a refund: a mismatching confirmation drained onto a counted row changes nothing and is noted. */
	public function test_mismatch_drained_onto_a_counted_row_leaves_it_and_notes_it(): void {
		// Arrange: a tender completed the sale (completion 1 counts it); a mismatch is parked for it after.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = array_merge( $this->tender( '92.95' ), array( 'method_id' => 'pos_card' ) );
		Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );
		$this->assertTrue( Completion_History::of( $this->fresh( $id ) )->counted( $input['id'] ), 'Precondition: counted.' );
		update_option(
			'wcpos_pending_settlements',
			array(
				$input['id'] => array(
					'patch'     => array(
						'status' => 'captured',
						'amount' => '100.00',
					),
					'parked_at' => time(),
				),
			),
			false
		);
		$before = $this->stored_rows( $id );

		// Act.
		$replayed = $this->replay_record( $id, $input );

		// Assert.
		$this->assertSame( $before, $this->stored_rows( $id ) );
		$this->assertSame( $before[0], $replayed );
		$this->assertStringContainsString( 'amount mismatch', $this->notes( $id ) );
		$this->assertSame( array(), (array) get_option( 'wcpos_pending_settlements', array() ) );
	}

	/** The same on apply_result(): a counted captured row is never failed by a confirmation. */
	public function test_apply_result_mismatch_on_a_counted_row_leaves_it_and_notes_it(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$input = array_merge( $this->tender( '92.95' ), array( 'method_id' => 'pos_card' ) );
		Ledger::instance()->record( $order, $input, array(), $this->record_command( $input ) );
		$before = $this->stored_rows( $id );

		// Act.
		$answer = Ledger::instance()->apply_result(
			wc_get_order( $id ),
			$input['id'],
			array(
				'status' => 'captured',
				'amount' => '100.00',
			)
		);

		// Assert.
		$this->assertSame( $before, $this->stored_rows( $id ) );
		$this->assertSame( $before[0], $answer );
		$this->assertStringContainsString( 'amount mismatch', $this->notes( $id ) );
		$this->assertSame( 'completed', $this->stored_status( $id ) );
	}

	/** The sweep drains a confirmation the arrival never drained before it completes: a mismatch is the answer. */
	public function test_sweeper_drains_a_parked_mismatch_before_completing(): void {
		// Arrange: a covering record stores its held rows, a mismatch parks during that save, and the drain never
		// runs (the parking lock is taken elsewhere right after the confirmation parked).
		$order  = $this->create_pos_order();
		$id     = $order->get_id();
		$input  = array_merge( $this->tender( '92.95' ), array( 'method_id' => 'pos_card' ) );
		$busy   = new Order_Lock();
		$parked = false;
		$park   = static function ( $saving ) use ( $id, $input, $busy, &$parked ): void {
			if ( ! $parked && $saving->get_id() === $id ) {
				$parked = true;
				wcpos_settle_payment(
					$input['id'],
					array(
						'status' => 'captured',
						'amount' => '100.00',
					)
				);
				$busy->acquire( 0 );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $park, 1 );
		try {
			$stopped = Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $park, 1 );
			$busy->release( 0 );
		}
		$this->assertWPError( $stopped, 'Precondition: the drain did not run.' );
		$this->assertNull( $this->fresh( $id )->get_date_paid( 'edit' ), 'Precondition: not paid before the sweep.' );

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$saved = $this->fresh( $id );
		$this->assertNull( $saved->get_date_paid( 'edit' ) );
		$this->assertCount( 0, Completion_History::of( $saved )->entries() );
		$this->assertSame( 'failed', $this->stored_rows( $id )[0]['status'] );
		$this->assertStringContainsString( 'amount mismatch', $this->notes( $id ) );
	}

	/** Refused tenders on a legacy paid sale are history, not evidence: no completion, no fiscal sale. */
	public function test_refused_tenders_on_a_legacy_paid_sale_complete_nothing(): void {
		// Arrange: paid under 1.x, no ledger, no completion history.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$paid  = $this->fresh( $id );
		$paid->set_date_paid( time() );
		$paid->set_status( 'completed' );
		$this->as_if_paid_hooks_never_ran( fn() => $paid->save() );

		// Act.
		$first  = Ledger::instance()->record( wc_get_order( $id ), $this->tender( '5.00' ) );
		$second = Ledger::instance()->record( wc_get_order( $id ), $this->tender( '5.00' ) );

		// Assert.
		$this->assertWPError( $first );
		$this->assertWPError( $second );
		$this->assertCount( 0, Completion_History::of( $this->fresh( $id ) )->entries() );
		$this->assertSame(
			0,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $id,
					'type' => 'sale',
				)
			)
		);
	}

	/**
	 * The one rule for a completion: storage shows uncounted covering money with no confirmation outstanding,
	 * or a command owed at the current completion count.
	 *
	 * @dataProvider completion_evidence
	 *
	 * @param callable $arrange fn( Test_Ledger_Write, int $id ): void, shaping storage.
	 * @param bool     $due     Whether a completion is due.
	 */
	public function test_completion_due_reads_only_storage_evidence( callable $arrange, bool $due ): void {
		// Arrange.
		$order = $this->create_pos_order();
		$arrange( $this, $order->get_id() );
		$saved = $this->fresh( $order->get_id() );

		// Act.
		$answer = Ledger::instance()->completion_due( $saved, Ledger::instance()->read( $saved ) );

		// Assert.
		$this->assertSame( $due, $answer );
	}

	/** Storage shapes and whether each is evidence of a completion due. */
	public function completion_evidence(): array {
		$owed = static fn( int $n ) => array_merge(
			Command_Log::entry( 'sha256:x', 'record' ),
			array(
				'phase'     => Command_Log::OWED,
				'owed_at_n' => $n,
			)
		);
		return array(
			'covering uncounted rows'                  => array( static fn( $test, $id ) => $test->seed( $id, array( $test->cash( '92.95' ) ) ), true ),
			'covering rows a completion counted'       => array(
				static function ( $test, $id ) {
					$row = $test->cash( '92.95' );
					$test->seed( $id, array( $row ) );
					$test->seed_completion( $id, array( $row['id'] ) );
				},
				false,
			),
			'covering rows, one confirmation parked'   => array(
				static function ( $test, $id ) {
					$row = $test->cash( '92.95' );
					$test->seed( $id, array( $row ) );
					update_option(
						'wcpos_pending_settlements',
						array(
							$row['id'] => array(
								'patch' => array( 'status' => 'captured' ),
								'parked_at' => time(),
							),
						),
						false
					);
				},
				false,
			),
			'failed rows only, paid legacy order'      => array(
				static function ( $test, $id ) {
					$test->seed( $id, array( $test->cash( '92.95', 'failed' ) ) );
					$order = wc_get_order( $id );
					$order->set_date_paid( time() );
					$order->set_status( 'completed' );
					$order->save();
				},
				false,
			),
			'owed mark at the current count'           => array( static fn( $test, $id ) => $test->seed( $id, array( Command_Log::with_on_row( $test->cash( '10.00' ), wp_generate_uuid4(), $owed( 0 ) ) ) ), true ),
			'owed mark at a stale count'               => array(
				static function ( $test, $id ) use ( $owed ) {
					$row = Command_Log::with_on_row( $test->cash( '10.00' ), wp_generate_uuid4(), $owed( 0 ) );
					$test->seed( $id, array( $row ) );
					$test->seed_completion( $id, array() );
				},
				false,
			),
			'zero-total close owed at the current count' => array(
				static function ( $test, $id ) use ( $owed ) {
					$order = wc_get_order( $id );
					$order->set_total( '0.00' );
					Command_Log::remember( $order, wp_generate_uuid4(), array_merge( $owed( 0 ), array( 'kind' => 'close' ) ) );
					$order->save();
				},
				true,
			),
		);
	}

	/**
	 * Seed rows (for the evidence table).
	 *
	 * @param int   $id   Order id.
	 * @param array $rows Rows.
	 */
	public function seed( int $id, array $rows ): void {
		$this->seed_rows( $id, $rows );
	}

	/**
	 * A cash row (for the evidence table).
	 *
	 * @param string $amount Amount.
	 * @param string $status Status.
	 */
	public function cash( string $amount, string $status = 'captured' ): array {
		return $this->row( $amount, $status );
	}

	/**
	 * Seed completion 1, counting the named rows (for the evidence table).
	 *
	 * @param int   $id          Order id.
	 * @param array $payment_ids Counted row ids.
	 */
	public function seed_completion( int $id, array $payment_ids ): void {
		$order = $this->fresh( $id );
		$entry = array(
			'id'              => wp_generate_uuid4(),
			'n'               => 1,
			'at_gmt'          => gmdate( 'c' ),
			'payment_ids'     => $payment_ids,
			'reopened_at_gmt' => gmdate( 'c' ),
		);
		$order->update_meta_data( Completion_Stamp::META_KEY, wp_json_encode( array( $entry ) ) );
		$order->save_meta_data();
	}

	/** A mismatching confirmation parked while the arrival is being stored is drained before the paid transition: no completion. */
	public function test_record_with_mismatch_parked_during_its_rows_save_does_not_complete(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$id     = $order->get_id();
		$input  = array_merge( $this->tender( '92.95' ), array( 'method_id' => 'pos_card' ) );
		$parked = false;
		$park   = static function ( $saving ) use ( $id, $input, &$parked ): void {
			if ( $parked || $saving->get_id() !== $id ) {
				return;
			}
			$parked = true;
			wcpos_settle_payment(
				$input['id'],
				array(
					'status' => 'captured',
					'amount' => '100.00',
				)
			);
		};
		add_action( 'woocommerce_before_order_object_save', $park, 1 );

		// Act.
		try {
			$answer = Ledger::instance()->record( wc_get_order( $id ), $input, array(), $this->record_command( $input ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $park, 1 );
		}

		// Assert.
		$this->assertTrue( $parked, 'Precondition: the confirmation parked inside the arrival save.' );
		$this->assertWPError( $answer );
		$this->assertSame( 'wcpos_amount_mismatch', $answer->get_error_code() );
		$this->assertNull( $this->fresh( $id )->get_date_paid( 'edit' ) );
		$this->assertCount( 0, Completion_History::of( $this->fresh( $id ) )->entries() );
		$this->assertSame( 'failed', $this->stored_rows( $id )[0]['status'] );
	}

	/** A legacy paid sale (no ledger, no completion history) closed twice stays as it was: no completion, no fiscal sale, no marker. */
	public function test_close_on_legacy_paid_sale_twice_creates_no_completion(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$id    = $order->get_id();
		$paid  = $this->fresh( $id );
		$paid->set_date_paid( time() );
		$paid->set_status( 'completed' );
		$this->as_if_paid_hooks_never_ran( fn() => $paid->save() );
		$first  = new Money_Command( wp_generate_uuid4(), 'sha256:one', 'close' );
		$second = new Money_Command( wp_generate_uuid4(), 'sha256:two', 'close' );

		// Act.
		Ledger::instance()->close( wc_get_order( $id ), $first );
		$closed = Ledger::instance()->close( wc_get_order( $id ), $second );

		// Assert.
		$saved = $this->fresh( $id );
		$this->assertNull( $closed['completion'] );
		$this->assertCount( 0, Completion_History::of( $saved )->entries() );
		$this->assertSame(
			0,
			( new Fiscal_Record_Store() )->count(
				array(
					'order_id' => $id,
					'type' => 'sale',
				)
			)
		);
		foreach ( array( $first, $second ) as $command ) {
			$entry = Command_Log::find( $saved, array(), $command->id() );
			$this->assertSame( $command->fp(), $entry['fp'] );
			$this->assertArrayNotHasKey( 'phase', $entry );
			$this->assertArrayNotHasKey( 'owed_at_n', $entry );
		}
	}

	/** A completion whose cleanup was lost after it landed is answered retryable, and the next write resumes the cleanup once. */
	public function test_completion_cleanup_lost_after_the_completion_is_resumed_by_the_next_write(): void {
		// Arrange: a sent sale on-hold with its stamp; the covering tender's command owes the completion. Once the
		// completion is stored, the next write of the ledger meta is lost.
		$id      = $this->create_stamped_on_hold_order();
		$command = wp_generate_uuid4();
		$row     = Command_Log::with_on_row( $this->row( '92.95' ), $command, Command_Log::entry( 'sha256:cash', 'record' ) );
		$armed   = false;
		$lost    = 0;
		$arm     = static function () use ( &$armed ): void {
			$armed = true;
		};
		$lose    = static function ( $sql ) use ( &$armed, &$lost ) {
			if ( $armed && 0 === $lost && is_string( $sql ) && preg_match( '/^\s*UPDATE/i', $sql ) && false !== strpos( $sql, Ledger::META_KEY ) ) {
				++$lost;
				return 'SELECT 0';
			}
			return $sql;
		};
		add_action( 'wcpos_sale_completed', $arm, 1 );
		add_filter( 'query', $lose );
		$caught = null;
		try {
			Ledger::instance()->write( $id, fn( Ledger_Draft $draft ) => $draft->put( $row ) );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			remove_action( 'wcpos_sale_completed', $arm, 1 );
			remove_filter( 'query', $lose );
		}
		$paid = 0;
		add_action(
			'woocommerce_pre_payment_complete',
			function () use ( &$paid ): void {
				++$paid;
			}
		);

		// Act.
		Ledger::instance()->write( $id );

		// Assert.
		$this->assertSame( 1, $lost, 'Precondition: the cleanup was lost after the completion landed.' );
		$this->assertSame( 'completion', $caught ? $caught->stage : null );
		$saved = $this->fresh( $id );
		$this->assertSame( 0, $paid );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertNull( Gateway_Submission::read_stamp( $saved ) );
		$this->assertArrayNotHasKey( 'phase', $this->stored_rows( $id )[0]['commands'][ $command ] );
	}

	/** A standing completion whose cleanup never landed (stamp left, a debt taken before it) is cleaned by any write, completing nothing. */
	public function test_write_on_standing_completion_with_cleanup_undone_resumes_it(): void {
		// Arrange: completion 1 stands; then storage as a cleanup that never landed leaves it.
		$id    = $this->create_stamped_on_hold_order();
		$entry = array_merge(
			Command_Log::entry( 'sha256:cash', 'record' ),
			array(
				'phase'     => Command_Log::OWED,
				'owed_at_n' => 0,
			)
		);
		$row   = Command_Log::with_on_row( $this->row( '92.95' ), wp_generate_uuid4(), $entry );
		Ledger::instance()->write( $id, fn( Ledger_Draft $draft ) => $draft->put( $this->row( '92.95' ) ) );
		$this->seed_rows( $id, array( $row ) );
		$this->seed_stamp( $id );
		$saved = $this->fresh( $id );
		$this->assertSame( 1, (int) Completion_History::of( $saved )->standing()['n'], 'Precondition: completion 1 stands.' );
		$this->assertNotNull( Gateway_Submission::read_stamp( $saved ), 'Precondition: the stamp was left.' );
		$paid = 0;
		add_action(
			'woocommerce_pre_payment_complete',
			function () use ( &$paid ): void {
				++$paid;
			}
		);

		// Act.
		Ledger::instance()->write( $id );

		// Assert.
		$saved = $this->fresh( $id );
		$this->assertSame( 0, $paid );
		$this->assertCount( 1, Completion_History::of( $saved )->entries() );
		$this->assertNull( Gateway_Submission::read_stamp( $saved ) );
		$this->assertFalse( Command_Log::stale( $saved, $this->stored_rows( $id ) ) );
		$this->assertArrayNotHasKey( 'phase', $this->stored_rows( $id )[0]['commands'][ array_key_first( $row['commands'] ) ] );
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
		$row    = Command_Log::with_on_row( $row, $row['id'], Command_Log::entry( 'sha256:pay', 'record' ) );
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
			array( 'woocommerce_order_status_completed', 'wc_maybe_reduce_stock_levels', 10, 1 ),
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
	 * The till's input for a cash tender.
	 *
	 * @param string $amount Amount.
	 */
	private function tender( string $amount ): array {
		return array(
			'id'        => wp_generate_uuid4(),
			'method_id' => 'pos_cash',
			'amount'    => $amount,
		);
	}

	/**
	 * The admitted `record` command for a tender: its row id, its body fingerprint (as the route builds it).
	 *
	 * @param array $input Tender input.
	 */
	private function record_command( array $input ): Money_Command {
		return new Money_Command( $input['id'], Command_Log::fingerprint( 'record', '', array( 'payment' => $input ) ), 'record' );
	}

	/**
	 * The same `record` sent again as the route runs it (`Command_Log::run()`, which heals a replay; a replay
	 * never meets the revision). Answers what `record()` answers from the stored row.
	 *
	 * @param int   $id    Order id.
	 * @param array $input Tender input.
	 * @return mixed
	 */
	private function replay_record( int $id, array $input ) {
		return Command_Log::run( wc_get_order( $id ), $this->record_command( $input ), fn( Money_Command $c ) => Ledger::instance()->record( wc_get_order( $id ), $input, array(), $c ) );
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
	 * An open POS order whose one line is priced at its 92.95 total, so WooCommerce's own sums agree with it.
	 */
	private function priced_pos_order(): \WC_Order {
		$order = $this->create_pos_order();
		foreach ( $order->get_items() as $item ) {
			$item->set_subtotal( '92.95' );
			$item->set_total( '92.95' );
			$item->save();
		}
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

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A fixture stays with its tests, as Test_Ledger keeps its handlers.
/** A recordable card leg whose reader takes tips. */
class Tip_Test_Handler extends Manual_Handler {

	/**
	 * The manual descriptor, tipping on the reader.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$descriptor                         = parent::describe( $gateway );
		$descriptor['capture']['mode']      = 'tip_test';
		$descriptor['capabilities']['tips'] = 'on_reader';
		return $descriptor;
	}
}

/** A provider leg whose capture the provider confirms, counted. */
class Capture_Test_Handler extends Manual_Handler {
	/**
	 * Provider capture calls.
	 *
	 * @var int
	 */
	public static $captures = 0;

	/**
	 * The provider confirms the capture.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Provider context.
	 */
	public function capture( array $row, array $context ) {
		++self::$captures;
		return array( 'status' => 'captured' );
	}
}
