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
 * change stages on it and saves nothing; `Ledger::write()` stores it all in one save (S1), with the entry of
 * the command it runs for (`Command_Log::stage()`).
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
	 * Attempt patches to stage, by lowercase attempt id.
	 *
	 * @var array<string, array>
	 */
	private $attempts = array();

	/**
	 * Where the sale completed, named by a change that vouches for a paid transition made outside the ledger
	 * (`Completion_Stamp::defer()`); null leaves the write's own origin and its own decision.
	 *
	 * @var Completion_Origin|null
	 */
	private $origin = null;

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

	/**
	 * Remove a row: only a refused intent may vanish (`Ledger::write()` enforces it).
	 *
	 * @param string $id Payment id.
	 */
	public function drop( string $id ): void {
		unset( $this->rows[ strtolower( $id ) ] );
	}

	/**
	 * Stage an attempt's fate and detail over what is staged for it already. Only {@see Attempts} calls it.
	 *
	 * @param string $id     Attempt id.
	 * @param string $fate   `sent`, `settled`, `paid`, `cancelled` or `superseded`.
	 * @param array  $detail Entry fields to set.
	 */
	public function attempt( string $id, string $fate, array $detail = array() ): void {
		$id                    = strtolower( $id );
		$this->attempts[ $id ] = array_merge( $this->attempts[ $id ] ?? array(), $detail, array( 'fate' => $fate ) );
	}

	/** The staged attempt patches, by attempt id. */
	public function attempts(): array {
		return $this->attempts;
	}

	/**
	 * Vouch that the sale completed at this origin: a paid order with no standing completion gets one in this
	 * write's S3, as a sale this write's own paid transition completes does.
	 *
	 * @param Completion_Origin $origin The origin.
	 */
	public function completes_at( Completion_Origin $origin ): void {
		$this->origin = $origin;
	}

	/** The origin the change named, or null. */
	public function origin(): ?Completion_Origin {
		return $this->origin;
	}
}
