<?php
/**
 * Completion history read model tests (ADR 0040 rule 1c).
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;

/** Every question asked of `_wcpos_completions`, answered from one decode. */
class Test_Completion_History extends WC_Unit_Test_Case {
	private const SESSION_A = 'aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa';
	private const SESSION_B = 'bbbbbbbb-2222-4bbb-8bbb-bbbbbbbbbbbb';
	private const REGISTER  = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

	/** A reopened latest entry is the latest, not the standing one; a re-completion stands. */
	public function test_standing_after_a_reopen_is_null_and_latest_is_the_reopened_entry(): void {
		// Arrange.
		$order = $this->order_with( array( $this->entry( 1, array( 'reopened_at_gmt' => '2026-10-22T10:00:00+00:00' ) ) ) );

		// Act.
		$history = Completion_History::of( $order );

		// Assert.
		$this->assertNull( $history->standing() );
		$this->assertSame( 1, $history->latest()['n'] );
		$this->assertFalse( $history->stands() );
		$this->assertFalse( $history->is_empty() );
	}

	/** A sale completed again after its reopen stands on its new entry. */
	public function test_standing_after_a_re_completion_is_the_new_entry(): void {
		// Arrange.
		$order = $this->order_with(
			array(
				$this->entry( 1, array( 'reopened_at_gmt' => '2026-10-22T10:00:00+00:00' ) ),
				$this->entry( 2 ),
			)
		);

		// Act.
		$history = Completion_History::of( $order );

		// Assert.
		$this->assertSame( 2, $history->standing()['n'] );
		$this->assertSame( $history->latest(), $history->standing() );
		$this->assertTrue( $history->stands() );
		$this->assertCount( 2, $history->entries() );
	}

	/** Before the history existed a paid order in a completed status stands; an unpaid one does not. */
	public function test_stands_with_no_history_falls_back_to_paid_in_a_completed_status(): void {
		// Arrange.
		$paid = OrderHelper::create_order();
		$paid->set_status( 'completed' );
		$paid->set_date_paid( time() );
		$paid->save();
		$unpaid = OrderHelper::create_order();
		$unpaid->set_status( 'pending' );
		$unpaid->save();

		// Act.
		$legacy = Completion_History::of( wc_get_order( $paid->get_id() ) );
		$open   = Completion_History::of( wc_get_order( $unpaid->get_id() ) );

		// Assert.
		$this->assertTrue( $legacy->is_empty() );
		$this->assertTrue( $legacy->stands() );
		$this->assertNull( $legacy->standing() );
		$this->assertFalse( $open->stands() );
	}

	/** Entries are found by number and by id; an unknown one is null. */
	public function test_by_n_and_by_id_find_the_entry_or_null(): void {
		// Arrange.
		$first  = $this->entry( 1, array( 'reopened_at_gmt' => '2026-10-22T10:00:00+00:00' ) );
		$second = $this->entry( 2 );
		$order  = $this->order_with( array( $first, $second ) );

		// Act.
		$history = Completion_History::of( $order );

		// Assert.
		$this->assertSame( $first['id'], $history->by_n( 1 )['id'] );
		$this->assertSame( 2, $history->by_id( $second['id'] )['n'] );
		$this->assertNull( $history->by_n( 3 ) );
		$this->assertNull( $history->by_id( wp_generate_uuid4() ) );
	}

	/** A payment row counted by any completion is counted, whatever the case of its id. */
	public function test_counted_matches_payment_ids_case_insensitively(): void {
		// Arrange.
		$row   = 'DDDDDDDD-DDDD-4DDD-8DDD-DDDDDDDDDDDD';
		$order = $this->order_with(
			array(
				$this->entry(
					1,
					array(
						'payment_ids'     => array( strtolower( $row ) ),
						'reopened_at_gmt' => '2026-10-22T10:00:00+00:00',
					)
				),
				$this->entry( 2 ),
			)
		);

		// Act.
		$history = Completion_History::of( $order );

		// Assert.
		$this->assertTrue( $history->counted( $row ) );
		$this->assertFalse( $history->counted( wp_generate_uuid4() ) );
	}

	/** A sale reopened and completed again in one session is two completions there, and lists once. */
	public function test_a_re_completed_sale_counts_twice_in_its_session_and_lists_once(): void {
		// Arrange.
		$twice = $this->order_with(
			array(
				$this->entry(
					1,
					array(
						'session'         => self::SESSION_A,
						'reopened_at_gmt' => '2026-10-22T10:00:00+00:00',
					)
				),
				$this->entry( 2, array( 'session' => self::SESSION_A ) ),
			)
		);
		$elsewhere = $this->order_with( array( $this->entry( 1, array( 'session' => self::SESSION_B ) ) ) );

		// Act.
		$listed = Completion_History::orders_in_session( self::SESSION_A );

		// Assert.
		$this->assertSame( array( $twice->get_id() ), $listed );
		$this->assertSame( 2, Completion_History::of( wc_get_order( $twice->get_id() ) )->in_session( self::SESSION_A ) );
		$this->assertSame( 0, Completion_History::of( wc_get_order( $elsewhere->get_id() ) )->in_session( self::SESSION_A ) );
	}

	/** The standing entry's till tuple is whole: an absent counter stays null, not the till meta's. */
	public function test_till_names_the_standing_entry_and_keeps_its_null_counter(): void {
		// Arrange.
		$order = $this->order_with(
			array(
				$this->entry( 1, array( 'reopened_at_gmt' => '2026-10-22T10:00:00+00:00' ) ),
				$this->entry(
					2,
					array(
						'register'  => self::REGISTER,
						'session'   => self::SESSION_B,
						'sale_time' => '2026-10-22T11:00:00+00:00',
						'sale_tz'   => 'Europe/Madrid',
						'counter'   => null,
					)
				),
			)
		);

		// Act.
		$till = Completion_History::of( $order )->till();

		// Assert.
		$this->assertSame(
			array(
				'register'  => self::REGISTER,
				'session'   => self::SESSION_B,
				'sale_time' => '2026-10-22T11:00:00+00:00',
				'sale_tz'   => 'Europe/Madrid',
				'counter'   => null,
			),
			$till
		);
	}

	/** With nothing standing, the till tuple is the order's write-once till meta. */
	public function test_till_with_nothing_standing_reads_the_till_meta(): void {
		// Arrange.
		$order = $this->order_with( array( $this->entry( 1, array( 'reopened_at_gmt' => '2026-10-22T10:00:00+00:00' ) ) ) );

		// Act.
		$till = Completion_History::of( $order )->till();

		// Assert.
		$this->assertSame(
			array(
				'register'  => self::REGISTER,
				'session'   => self::SESSION_A,
				'sale_time' => '2026-10-22T09:00:00+00:00',
				'sale_tz'   => 'UTC',
				'counter'   => '4',
			),
			$till
		);
	}

	/**
	 * A completion entry.
	 *
	 * @param int   $n      Completion number.
	 * @param array $fields Fields to override.
	 */
	private function entry( int $n, array $fields = array() ): array {
		return array_merge(
			array(
				'id'              => wp_generate_uuid4(),
				'n'               => $n,
				'at_gmt'          => '2026-10-22T09:00:00+00:00',
				'sale_time'       => null,
				'sale_tz'         => null,
				'register'        => null,
				'session'         => null,
				'counter'         => null,
				'total'           => '10.00',
				'payment_ids'     => array(),
				'reopened_at_gmt' => null,
			),
			$fields
		);
	}

	/**
	 * A saved order with the till tuple and this history, read fresh.
	 *
	 * @param array[] $entries Entries, oldest first.
	 */
	private function order_with( array $entries ): \WC_Order {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_wcpos_register', self::REGISTER );
		$order->update_meta_data( '_wcpos_session', self::SESSION_A );
		$order->update_meta_data( '_wcpos_sale_time', '2026-10-22T09:00:00+00:00' );
		$order->update_meta_data( '_wcpos_sale_tz', 'UTC' );
		$order->update_meta_data( '_wcpos_sale_counter', '4' );
		$order->update_meta_data( Completion_Stamp::META_KEY, wp_json_encode( $entries ) );
		$order->save();
		return wc_get_order( $order->get_id() );
	}
}
