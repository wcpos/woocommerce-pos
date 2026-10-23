<?php
/**
 * The payments sweep's fiscal sale record recovery (#2378).
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Payments_Sweeper;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Register_Sale;

/**
 * A completion that stands is owed exactly one sale record, keyed by its entry (ADR 0040: the pair
 * order id + n). The sweep writes the parked document, or builds the record a crash between the entry's
 * save and `wcpos_sale_completed` never wrote, and never a second one.
 */
class Test_Fiscal_Record_Sweep extends WCPOS_REST_Unit_Test_Case {
	private const RECOVERED_NOTE = 'POS fiscal sale record recovered.';

	/** Restore the writer hooks; the sweep sees completions as soon as they are made. */
	public function setUp(): void {
		parent::setUp();
		// WP restores hooks between tests, while service singletons survive.
		$writers = Fiscal_Record_Writers::instance();
		add_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $writers, 'handle_cancellation' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $writers, 'handle_refund' ), 10, 2 );
		foreach ( Fiscal_Record_Store::TYPES as $type ) {
			delete_option( 'wcpos_fiscal_sequence_' . $type );
		}
		add_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
	}

	/** Drop the threshold override. */
	public function tearDown(): void {
		remove_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		parent::tearDown();
	}

	/** A parked document is inserted once by the sweep, its park cleared, one note left. */
	public function test_sweep_parked_sale_document_is_recorded_once_and_unparked_with_a_note(): void {
		// Arrange: the sale's last command completed it, and its record insert failed.
		$order = $this->order();
		$this->with_sale_inserts_failing(
			function () use ( $order ): void {
				Register_Sale::complete( $order );
			}
		);
		$entry = Completion_History::of( wc_get_order( $order->get_id() ) )->standing();
		$this->assertSame( array( $entry['id'] ), array_keys( $this->parked( $order->get_id() ) ) );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );

		// Act: twice, the second a no-op.
		( new Payments_Sweeper() )->run();
		( new Payments_Sweeper() )->run();

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( $entry['id'], $sales[0]['source_id'] );
		$this->assertSame( 1, $sales[0]['payload']['fiscal']['completion'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
		$this->assertSame( 1, $this->recovered_notes( $order->get_id() ) );
	}

	/** A standing completion whose record was never written (the crash gap) gets it from the entry. */
	public function test_sweep_standing_completion_without_a_record_writes_it_with_its_n_tuple_and_cashier(): void {
		// Arrange: completion 1 recorded; reopened; completion 2 at another register with the writer not listening.
		$cashier = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$order   = $this->order();
		$order->update_meta_data( '_pos_user', $cashier );
		$order->save();
		Register_Sale::complete( $order );
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$till = array(
			'register_id' => wp_generate_uuid4(),
			'session_id'  => wp_generate_uuid4(),
			'sale_time'   => '2026-10-22T18:30:00+01:00',
			'sale_tz'     => 'Europe/Dublin',
		);
		$this->without_the_sale_writer(
			function () use ( $order, $till ): void {
				Register_Sale::complete( $order, $till );
			}
		);
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 2, $history );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 2, $sales );
		$this->assertSame( $history[1]['id'], $sales[1]['source_id'] );
		$this->assertSame( 2, $sales[1]['payload']['fiscal']['completion'] );
		$this->assertSame( $sales[0]['id'], $sales[1]['corrects_record_id'] );
		$this->assertSame( $till['register_id'], $sales[1]['register_id'] );
		$this->assertSame( $till['session_id'], $sales[1]['session_id'] );
		$this->assertSame( $till['sale_time'], $sales[1]['device_time'] );
		$this->assertSame( $till['sale_tz'], $sales[1]['device_tz'] );
		$this->assertSame( $cashier, (int) $sales[1]['cashier_id'] );
		$this->assertSame( 1, $this->recovered_notes( $order->get_id() ) );
	}

	/** A completion whose document build threw (nothing parked) is written by the next run. */
	public function test_sweep_completion_whose_build_threw_is_written_once_the_cause_clears(): void {
		// Arrange.
		$order = $this->order();
		$throw = static function () {
			throw new \RuntimeException( 'receipt data unavailable' );
		};
		add_filter( 'wcpos_receipt_data', $throw );
		try {
			Register_Sale::complete( $order );
		} finally {
			remove_filter( 'wcpos_receipt_data', $throw );
		}
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( Completion_History::of( wc_get_order( $order->get_id() ) )->standing()['id'], $sales[0]['source_id'] );
	}

	/** A completion whose record landed is left alone: no second record, no note. */
	public function test_sweep_completion_with_its_record_writes_nothing(): void {
		// Arrange.
		$order = $this->order();
		Register_Sale::complete( $order );
		$before = $this->sales( $order->get_id() );
		$this->assertCount( 1, $before );

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$this->assertSame( $before, $this->sales( $order->get_id() ) );
		$this->assertSame( 0, $this->recovered_notes( $order->get_id() ) );
	}

	/** A completion reopened before its record landed, with nothing parked, is never built from the live order. */
	public function test_sweep_reopened_completion_without_a_record_writes_nothing(): void {
		// Arrange.
		$order = $this->order();
		$this->without_the_sale_writer(
			function () use ( $order ): void {
				Register_Sale::complete( $order );
			}
		);
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$this->assertNull( Completion_History::of( wc_get_order( $order->get_id() ) )->standing() );

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
	}

	/** An order paid on the storefront has no register completion and is owed no sale record. */
	public function test_sweep_storefront_completion_writes_nothing(): void {
		// Arrange.
		$order = $this->order( false );
		$order->payment_complete();
		$this->assertTrue( Completion_History::of( wc_get_order( $order->get_id() ) )->is_empty() );

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$this->assertSame( array(), ( new Fiscal_Record_Store() )->list( array( 'order_id' => $order->get_id() ) ) );
	}

	/** One run recovers at most the sweep's batch size of orders. */
	public function test_sweep_fiscal_recovery_respects_the_batch_size(): void {
		// Arrange: two orders owed a record.
		$batch = static fn() => 1;
		$first = $this->order();
		$other = $this->order();
		$this->without_the_sale_writer(
			function () use ( $first, $other ): void {
				Register_Sale::complete( $first );
				Register_Sale::complete( $other );
			}
		);
		add_filter( 'wcpos_payments_sweep_batch_size', $batch );

		// Act.
		try {
			( new Payments_Sweeper() )->run();
		} finally {
			remove_filter( 'wcpos_payments_sweep_batch_size', $batch );
		}

		// Assert.
		$this->assertSame( 1, count( $this->sales( $first->get_id() ) ) + count( $this->sales( $other->get_id() ) ) );
	}

	/** A parked document that keeps failing never starves the never-written pass, and rotates once tried. */
	public function test_sweep_chronically_failing_parked_document_rotates_behind_a_never_written_record(): void {
		// Arrange: A is parked and its insert keeps failing; B's completion was never written.
		$parked = $this->order();
		$this->with_sale_inserts_failing(
			function () use ( $parked ): void {
				Register_Sale::complete( $parked );
			}
		);
		$unwritten = $this->order();
		$this->without_the_sale_writer(
			function () use ( $unwritten ): void {
				Register_Sale::complete( $unwritten );
			}
		);
		$tries   = 0;
		$failing = static function ( $snapshot, $order_id ) use ( $parked, &$tries ) {
			if ( $parked->get_id() === (int) $order_id ) {
				++$tries;
				throw new \RuntimeException( 'fiscal service unavailable' );
			}
			return $snapshot;
		};
		$batch  = static fn() => 1;
		$minute = static fn() => MINUTE_IN_SECONDS;
		add_filter( 'wcpos_fiscal_snapshot_enrich', $failing, 10, 2 );
		add_filter( 'wcpos_payments_sweep_batch_size', $batch );

		// Act: two runs, then a third while A's last attempt is younger than the threshold.
		try {
			( new Payments_Sweeper() )->run();
			( new Payments_Sweeper() )->run();
			$after_two = $tries;
			add_filter( 'wcpos_payments_sweep_threshold', $minute, 20 );
			( new Payments_Sweeper() )->run();
		} finally {
			remove_filter( 'wcpos_payments_sweep_threshold', $minute, 20 );
			remove_filter( 'wcpos_payments_sweep_batch_size', $batch );
			remove_filter( 'wcpos_fiscal_snapshot_enrich', $failing, 10 );
		}

		// Assert.
		$this->assertCount( 1, $this->sales( $unwritten->get_id() ) );
		$this->assertSame( array(), $this->sales( $parked->get_id() ) );
		$this->assertSame( 1, $after_two );
		$this->assertSame( 1, $tries );
		$this->assertArrayHasKey( 'attempted_at', array_values( $this->parked( $parked->get_id() ) )[0] );
	}

	/** A completion younger than the sweep threshold is still its own request's to record. */
	public function test_sweep_completion_younger_than_the_threshold_is_left_to_its_request(): void {
		// Arrange.
		remove_filter( 'wcpos_payments_sweep_threshold', '__return_zero' );
		$order = $this->order();
		$this->without_the_sale_writer(
			function () use ( $order ): void {
				Register_Sale::complete( $order );
			}
		);

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
	}

	/** A completion older than the lookback window is outside the indexed candidate set. */
	public function test_sweep_completion_older_than_the_lookback_window_is_not_searched(): void {
		// Arrange.
		$order = $this->order();
		$this->without_the_sale_writer(
			function () use ( $order ): void {
				Register_Sale::complete( $order );
			}
		);
		$stored = wc_get_order( $order->get_id() );
		$stored->update_meta_data( Completion_Stamp::COMPLETED_AT_META_KEY, gmdate( 'c', time() - 8 * DAY_IN_SECONDS ) );
		$stored->save_meta_data(); // Seed the server-owned stamp directly.

		// Act.
		( new Payments_Sweeper() )->run();

		// Assert.
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
	}

	/**
	 * Create an order at a register.
	 *
	 * @param bool $pos Whether it originated at POS.
	 */
	private function order( bool $pos = true ): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( $pos ? 'woocommerce-pos' : 'checkout' );
		$order->set_status( $pos ? 'pos-open' : 'pending' );
		$order->set_total( '100.00' );
		foreach ( array(
			'_wcpos_register'  => wp_generate_uuid4(),
			'_wcpos_session'   => wp_generate_uuid4(),
			'_pos_store'       => 12,
			'_pos_user'        => get_current_user_id(),
			'_wcpos_sale_time' => '2026-09-11T10:00:00',
			'_wcpos_sale_tz'   => 'Europe/Madrid',
		) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return $order;
	}

	/**
	 * Run $work while the sale sequence cannot advance, so record() answers null and the document is parked.
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
	 * Run $work with the sale writer not listening: the completion entry is stored and nothing records
	 * or parks its sale, as when the request dies between the entry's save and `wcpos_sale_completed`.
	 *
	 * @param callable $work Work.
	 */
	private function without_the_sale_writer( callable $work ): void {
		$writers = Fiscal_Record_Writers::instance();
		remove_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10 );
		try {
			$work();
		} finally {
			add_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10, 2 );
		}
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
					'type'     => 'sale',
				)
			)
		);
	}

	/**
	 * The parked sale documents on the order, read from storage.
	 *
	 * @param int $order_id Order ID.
	 */
	private function parked( int $order_id ): array {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( Fiscal_Record_Writers::PENDING_META_KEY === $meta->meta_key ) {
				return (array) json_decode( (string) maybe_unserialize( $meta->meta_value ), true );
			}
		}
		return array();
	}

	/**
	 * How many recovery notes the order carries.
	 *
	 * @param int $order_id Order ID.
	 */
	private function recovered_notes( int $order_id ): int {
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' );
		return count( array_filter( $notes, static fn( $note ) => self::RECOVERED_NOTE === $note ) );
	}
}
