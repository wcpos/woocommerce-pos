<?php
/**
 * Where and when a completion happened, as its caller knows it.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;

/**
 * The origin `Completion_Stamp::append()` names a completion entry from (ADR 0040 rule 1): the provenance a
 * money command's body carried, whether order-pay is paying, the cashier. Immutable; `for_entry()` reads
 * only storage, so a retried S3 names what the first one would have.
 */
final class Completion_Origin {
	/** Entry field => [ request body field, till meta key whose validation it borrows ]. */
	private const FIELDS = array(
		'register'  => array( 'register_id', '_wcpos_register' ),
		'session'   => array( 'session_id', '_wcpos_session' ),
		'sale_time' => array( 'sale_time', '_wcpos_sale_time' ),
		'sale_tz'   => array( 'sale_tz', '_wcpos_sale_tz' ),
		'counter'   => array( 'sale_counter', '_wcpos_sale_counter' ),
	);

	/**
	 * The validated provenance, entry-shaped.
	 *
	 * @var array<string, string>
	 */
	private $provenance;

	/**
	 * Whether WooCommerce's order-pay form is paying the order (it may name the till tuple on any completion).
	 *
	 * @var bool
	 */
	private $order_pay;

	/**
	 * The cashier who completed the sale; 0 when unknown.
	 *
	 * @var int
	 */
	private $cashier;

	/**
	 * Hold an origin.
	 *
	 * @param array $provenance Body `provenance` (register_id, session_id, sale_time, sale_tz, sale_counter); an invalid field is dropped.
	 * @param bool  $order_pay  Whether order-pay is paying the order.
	 * @param int   $cashier    The cashier's user id; 0 when unknown.
	 */
	public function __construct( array $provenance = array(), bool $order_pay = false, int $cashier = 0 ) {
		$this->provenance = self::validated( $provenance, false );
		$this->order_pay  = $order_pay;
		$this->cashier    = max( 0, $cashier );
	}

	/**
	 * The origin an attempt kept at `Attempts::open()`: its submit's validated provenance and its cashier.
	 *
	 * @param array $entry The attempt's entry.
	 */
	public static function of_attempt( array $entry ): self {
		$origin             = new self( array(), false, (int) ( $entry['cashier_id'] ?? 0 ) );
		$origin->provenance = self::validated( is_array( $entry['origin'] ?? null ) ? $entry['origin'] : array(), true );
		return $origin;
	}

	/** The validated provenance, entry-shaped: what a sent submit keeps on its awaiting stamp. */
	public function provenance(): array {
		return $this->provenance;
	}

	/**
	 * The entry's provenance for completion n, from the first source that names any of it: this origin, the
	 * stored awaiting stamp, then (completion 1 or order-pay only) the write-once till tuple. Failing all three,
	 * the register and session of the latest captured row, the register that last took money. Nothing is
	 * invented: an absent value stays null. The cashier is this origin's, else the stamp's.
	 *
	 * @param WC_Order $fresh Order as storage holds it.
	 * @param int      $n     Completion number.
	 * @return array register, session, sale_time, sale_tz, counter, and cashier when one is known.
	 */
	public function for_entry( WC_Order $fresh, int $n ): array {
		$empty   = array_fill_keys( array_keys( self::FIELDS ), null );
		$stamp   = Attempts::of( $fresh )->awaiting() ?? array();
		$sources = array( $this->provenance, is_array( $stamp['provenance'] ?? null ) ? self::validated( $stamp['provenance'], true ) : array() );
		if ( 1 === $n || $this->order_pay ) {
			$sources[] = array_filter( self::till_tuple( $fresh ), static fn( $value ) => null !== $value );
		}
		$entry = null;
		foreach ( $sources as $source ) {
			if ( $source ) {
				$entry = array_merge( $empty, $source );
				break;
			}
		}
		if ( null === $entry ) {
			$latest            = self::latest_captured( $fresh );
			$entry             = $empty;
			$entry['register'] = $latest['register_id'] ?? null;
			$entry['session']  = $latest['session_id'] ?? null;
		}
		$cashier = $this->cashier ? $this->cashier : (int) ( $stamp['cashier_id'] ?? 0 );
		return $cashier > 0 ? $entry + array( 'cashier' => $cashier ) : $entry;
	}

	/**
	 * The write-once till tuple, entry-shaped.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function till_tuple( WC_Order $order ): array {
		$values = array();
		foreach ( self::FIELDS as $field => $keys ) {
			$values[ $field ] = $order->get_meta( $keys[1], true );
		}
		return array_merge( array_fill_keys( array_keys( self::FIELDS ), null ), self::validated( $values, true ) );
	}

	/**
	 * The most recently captured row on the order, if any: the register that last took money.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function latest_captured( WC_Order $order ): ?array {
		$latest = null;
		foreach ( Ledger::instance()->read( $order ) as $row ) {
			if ( 'captured' === ( $row['status'] ?? '' ) && ( null === $latest || strcmp( (string) ( $row['captured_at_gmt'] ?? '' ), (string) ( $latest['captured_at_gmt'] ?? '' ) ) >= 0 ) ) {
				$latest = $row;
			}
		}
		return $latest;
	}

	/**
	 * Keep each provenance field only when it passes the till meta's own validation; drop the rest.
	 *
	 * @param array $provenance   Body-shaped provenance, or entry-shaped when $entry_shaped.
	 * @param bool  $entry_shaped Whether the keys are already the entry's.
	 */
	private static function validated( array $provenance, bool $entry_shaped ): array {
		$valid = array();
		foreach ( self::FIELDS as $field => $keys ) {
			$value = $provenance[ $entry_shaped ? $field : $keys[0] ] ?? null;
			if ( Pos_Order_Audit::is_valid_till_value( $keys[1], $value ) ) {
				$valid[ $field ] = in_array( $field, array( 'register', 'session' ), true ) ? strtolower( (string) $value ) : (string) $value;
			}
		}
		return $valid;
	}
}
