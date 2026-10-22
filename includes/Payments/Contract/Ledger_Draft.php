<?php
/**
 * What one ledger write is about to store.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;

/**
 * Handed to a write's change: the order and its rows as storage held them under the lock. The
 * change stages on it and saves nothing; `Ledger::write()` stores it all in one save (S1).
 */
final class Ledger_Draft {
	/**
	 * The order, read under the lock.
	 *
	 * @var WC_Order
	 */
	private $order;

	/**
	 * Rows by lowercase id, in stored order.
	 *
	 * @var array<string, array>
	 */
	private $rows = array();

	/**
	 * Build the draft from the locked read.
	 *
	 * @param WC_Order $order Fresh order.
	 * @param array    $rows  Stored rows.
	 */
	public function __construct( WC_Order $order, array $rows ) {
		$this->order = $order;
		foreach ( $rows as $row ) {
			$this->rows[ strtolower( (string) $row['id'] ) ] = $row;
		}
	}

	/** The order, fresh and read under the lock: stage items, notes and props on it. */
	public function order(): WC_Order {
		return $this->order;
	}

	/** The rows as they will be stored. */
	public function rows(): array {
		return array_values( $this->rows );
	}

	/**
	 * One row by id.
	 *
	 * @param string $id Payment id.
	 */
	public function row( string $id ): ?array {
		return $this->rows[ strtolower( $id ) ] ?? null;
	}

	/**
	 * Add a row, or replace the row with its id.
	 *
	 * @param array $row Payment row with an `id`.
	 */
	public function put( array $row ): void {
		$row['id']                = strtolower( (string) ( $row['id'] ?? '' ) );
		$this->rows[ $row['id'] ] = $row;
	}
}
