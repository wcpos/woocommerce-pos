<?php
/**
 * The pure projection of payment rows onto an order.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Projection;
use WP_UnitTestCase;

/** One table: rows and the order's facts in, the order's payment fields and the completion out. */
class Test_Projection extends WP_UnitTestCase {
	/**
	 * Each row of the table projects as it says.
	 *
	 * @dataProvider projections
	 *
	 * @param array  $rows     Payment rows.
	 * @param string $total    Order total.
	 * @param string $status   Order status.
	 * @param bool   $stamped  Whether a sent submit awaits the customer.
	 * @param string $mode     Write mode.
	 * @param bool   $editable Whether the order is editable.
	 * @param array  $expected The projection.
	 */
	public function test_projection_of_rows_and_order_facts_matches_table( array $rows, string $total, string $status, bool $stamped, string $mode, bool $editable, array $expected ): void {
		// Arrange.
		$title = static fn( string $method_id ): string => strtoupper( $method_id );

		// Act.
		$projection = Projection::of( $rows, $total, $status, $stamped, $mode, $editable, $title );

		// Assert.
		$this->assertSame( $expected, $projection );
	}

	/** The table. */
	public function projections(): array {
		$cash = self::row( 'pos_cash', '92.95', 'captured', '2026-10-22T09:00:00+00:00' );
		$part = self::row( 'pos_cash', '50.00', 'captured', '2026-10-22T09:00:00+00:00' );
		$card = self::row( 'pos_card', '42.95', 'captured', '2026-10-22T09:01:00+00:00', array( 'payment_intent' => 'pi_1' ) );
		$gift = self::row( 'gift', '60.00', 'captured', '2026-10-22T08:00:00+00:00', array(), 'stored_value' );
		$none = self::none();
		return array(
			'no rows leaves the method and opens'      => array( array(), '92.95', 'pos-open', false, Ledger::MODE_AUTO, true, self::expect( 'pos-open' ) ),
			'a part payment is partial'                => array( array( $part ), '92.95', 'pos-open', false, Ledger::MODE_AUTO, true, self::expect( 'pos-partial', 'pos_cash', 'POS_CASH', '' ) ),
			'covering money completes'                 => array( array( $cash ), '92.95', 'pos-partial', false, Ledger::MODE_AUTO, true, self::expect( null, 'pos_cash', 'POS_CASH', '', true ) ),
			'a pending leg waits'                      => array( array( $part, self::row( 'pos_card', '42.95', 'pending', null ) ), '92.95', 'pos-partial', false, Ledger::MODE_AUTO, true, self::expect( 'pending', 'pos_cash', 'POS_CASH', '' ) ),
			'an authorization is not money'            => array( array( self::row( 'pos_card', '92.95', 'authorized', null ) ), '92.95', 'pos-open', false, Ledger::MODE_AUTO, true, self::expect( 'pos-open' ) ),
			'a completed order is never pulled back'   => array( array( $part ), '92.95', 'processing', false, Ledger::MODE_AUTO, false, self::expect( null, 'pos_cash', 'POS_CASH', '' ) ),
			'a sent submit owns a processing order'    => array( array( $cash ), '92.95', 'processing', true, Ledger::MODE_AUTO, false, self::expect( null, 'pos_cash', 'POS_CASH', '', true ) ),
			'a sent submit keeps its status unpaid'    => array( array(), '92.95', 'pending', true, Ledger::MODE_AUTO, true, $none ),
			'a money write never completes zero'       => array( array(), '0.00', 'pos-open', false, Ledger::MODE_AUTO, true, self::expect( 'pos-open' ) ),
			'close completes zero'                     => array( array(), '0.00', 'pos-open', false, Ledger::MODE_CLOSE, true, self::expect( null, null, null, null, true ) ),
			'close completes an editable on-hold sale' => array( array( $cash ), '92.95', 'on-hold', false, Ledger::MODE_CLOSE, true, self::expect( null, 'pos_cash', 'POS_CASH', '', true ) ),
			'auto never completes on-hold'             => array( array( $cash ), '92.95', 'on-hold', false, Ledger::MODE_AUTO, true, self::expect( null, 'pos_cash', 'POS_CASH', '' ) ),
			'close leaves a locked on-hold sale'       => array( array( $cash ), '92.95', 'on-hold', false, Ledger::MODE_CLOSE, false, self::expect( null, 'pos_cash', 'POS_CASH', '' ) ),
			'a succeeded refund uncovers the sale'     => array(
				array(
					array_merge(
						$cash,
						array(
							'refunds' => array(
								array(
									'amount' => '10.00',
									'status' => 'succeeded',
								),
								array(
									'amount' => '5.00',
									'status' => 'pending',
								),
							),
						)
					),
				),
				'92.95',
				'pos-open',
				false,
				Ledger::MODE_AUTO,
				true,
				self::expect( 'pos-partial', 'pos_cash', 'POS_CASH', '' ),
			),
			'stored value never names the method'      => array( array( $gift, $card ), '102.95', 'pos-partial', false, Ledger::MODE_AUTO, true, self::expect( null, 'pos_card', 'GIFT + POS_CARD', 'pi_1', true ) ),
			'equal tenders: the earliest pays'         => array( array( self::row( 'pos_card', '50.00', 'captured', '2026-10-22T09:05:00+00:00', array( 'transaction_id' => 'tx_late' ) ), self::row( 'pos_cash', '50.00', 'captured', null ), self::row( 'pos_card', '50.00', 'captured', '2026-10-22T09:00:00+00:00', array( 'transaction_id' => 'tx_early' ) ) ), '150.00', 'pos-partial', false, Ledger::MODE_AUTO, true, self::expect( null, 'pos_card', 'POS_CARD + POS_CASH', 'tx_early', true ) ),
		);
	}

	/**
	 * A payment row.
	 *
	 * @param string      $method   Method id.
	 * @param string      $amount   Amount.
	 * @param string      $status   Row status.
	 * @param string|null $captured Capture time.
	 * @param array       $refs     Provider refs.
	 * @param string      $kind     Tender kind.
	 */
	private static function row( string $method, string $amount, string $status, ?string $captured, array $refs = array(), string $kind = 'cash' ): array {
		return array(
			'id'              => wp_generate_uuid4(),
			'method_id'       => $method,
			'kind'            => $kind,
			'amount'          => $amount,
			'status'          => $status,
			'captured_at_gmt' => $captured,
			'provider_refs'   => $refs,
			'refunds'         => array(),
		);
	}

	/** The projection that changes nothing. */
	private static function none(): array {
		return self::expect( null );
	}

	/**
	 * A projection.
	 *
	 * @param string|null $status    Projected status.
	 * @param string|null $method    Paying method.
	 * @param string|null $title     Joined titles.
	 * @param string|null $txn       Transaction id.
	 * @param bool        $completes Whether the sale completes.
	 */
	private static function expect( ?string $status, ?string $method = null, ?string $title = null, ?string $txn = null, bool $completes = false ): array {
		return array(
			'status'    => $status,
			'method'    => $method,
			'title'     => $title,
			'txn'       => $txn,
			'completes' => $completes,
		);
	}
}
