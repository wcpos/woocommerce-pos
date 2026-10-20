<?php
/**
 * The money commands an order has answered, for replay by command id.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Sync\Revision;

/**
 * ADR 0040 rule 5: every money command is idempotent by a till-minted command id for as long as
 * the row it touched exists. A command that acts on a row (`intent`, `capture`, `void`, `refund`)
 * keeps `{ fp, kind, ref, at_gmt }` in that row's `commands` map; one with no row of its own
 * (`submit`, `cancel`, `close`) keeps `{ fp, kind, ref, outcome, at_gmt }` in the order's
 * `_wcpos_commands` map. Neither is ever evicted: an evicted id would run its command again.
 * No table and no option (`.ai/rules/db-tables.mdc`); both maps are server-owned order meta.
 */
final class Command_Log {
	/** Commands kept on the row they act on. */
	public const ROW_KINDS = array( 'intent', 'capture', 'void', 'refund' );
	/** Commands kept on the order, because they may complete with no row. */
	public const ORDER_KINDS = array( 'submit', 'cancel', 'close' );
	/** The order-level map, in `Pos_Order_Audit::SERVER_MONEY_META_KEYS` so a push cannot write it. */
	public const META_KEY = '_wcpos_commands';

	/**
	 * The body fingerprint: route, target and the body as sent (keys sorted at every depth, values
	 * untouched, so `10` and `"10.00"` differ). The same id on another route or row is another body.
	 *
	 * @param string $kind   Command kind.
	 * @param string $target Payment uuid, method id, or '' for close.
	 * @param array  $body   Request body.
	 */
	public static function fingerprint( string $kind, string $target, array $body ): string {
		unset( $body['command_id'], $body['revision'] );
		$canonical = Revision::canonicalize(
			array(
				'kind'   => $kind,
				'target' => strtolower( $target ),
				'body'   => $body,
			)
		);
		return 'sha256:' . hash( 'sha256', (string) wp_json_encode( $canonical ) );
	}

	/**
	 * One stored outcome.
	 *
	 * @param string      $fp      Body fingerprint.
	 * @param string      $kind    Command kind.
	 * @param mixed       $ref     Refund id, attempt id or completion id; null when none.
	 * @param string|null $outcome A submit's wire outcome.
	 */
	public static function entry( string $fp, string $kind, $ref = null, ?string $outcome = null ): array {
		$entry = array(
			'fp'     => $fp,
			'kind'   => $kind,
			'ref'    => $ref,
			'at_gmt' => gmdate( 'c' ),
		);
		if ( in_array( $kind, self::ORDER_KINDS, true ) ) {
			$entry['outcome'] = $outcome;
		}
		return $entry;
	}

	/**
	 * The stored entry for a command id anywhere on the order: every row's map, then the order map,
	 * then a row with no entry of its own whose id it is (a gateway mint, webview, adopted or
	 * earlier row: kind `row`, fp null, so any command reusing that id is a conflict).
	 * The answer carries `payment_id`, the row that holds it (null for the order map).
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $rows       Payment rows.
	 * @param string   $command_id Command id.
	 */
	public static function find( WC_Order $order, array $rows, string $command_id ): ?array {
		$command_id = strtolower( $command_id );
		foreach ( $rows as $row ) {
			$entry = $row['commands'][ $command_id ] ?? null;
			if ( is_array( $entry ) ) {
				return array_merge( $entry, array( 'payment_id' => (string) $row['id'] ) );
			}
		}
		$entry = self::read( $order )[ $command_id ] ?? null;
		if ( is_array( $entry ) ) {
			return array_merge( $entry, array( 'payment_id' => null ) );
		}
		foreach ( $rows as $row ) {
			if ( strtolower( (string) ( $row['id'] ?? '' ) ) === $command_id ) {
				$entry               = self::entry( '', 'row' );
				$entry['fp']         = null;
				$entry['payment_id'] = $command_id;
				return $entry;
			}
		}
		return null;
	}

	/**
	 * The row with the command's entry, unless the row already holds that id.
	 *
	 * @param array  $row        Payment row.
	 * @param string $command_id Command id.
	 * @param array  $entry      Entry.
	 */
	public static function with_on_row( array $row, string $command_id, array $entry ): array {
		$commands = is_array( $row['commands'] ?? null ) ? $row['commands'] : array();
		if ( ! isset( $commands[ strtolower( $command_id ) ] ) ) {
			$commands[ strtolower( $command_id ) ] = $entry;
		}
		$row['commands'] = $commands;
		return $row;
	}

	/**
	 * The row without a command's entry (a command that failed keeps none).
	 *
	 * @param array  $row        Payment row.
	 * @param string $command_id Command id; '' removes nothing.
	 */
	public static function without_on_row( array $row, string $command_id ): array {
		unset( $row['commands'][ strtolower( $command_id ) ] );
		return $row;
	}

	/**
	 * Stage an order-level entry; the caller saves.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $command_id Command id.
	 * @param array    $entry      Entry.
	 */
	public static function remember( WC_Order $order, string $command_id, array $entry ): void {
		$map                             = self::read( $order );
		$map[ strtolower( $command_id ) ] = $entry;
		$order->update_meta_data( self::META_KEY, (string) wp_json_encode( (object) $map ) );
	}

	/**
	 * Unstage an order-level entry (a submit that definitely failed); the caller saves.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $command_id Command id.
	 */
	public static function forget( WC_Order $order, string $command_id ): void {
		$map = self::read( $order );
		if ( isset( $map[ strtolower( $command_id ) ] ) ) {
			unset( $map[ strtolower( $command_id ) ] );
			$order->update_meta_data( self::META_KEY, (string) wp_json_encode( (object) $map ) );
		}
	}

	/**
	 * The order-level map.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function read( WC_Order $order ): array {
		$raw     = $order->get_meta( self::META_KEY, true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $decoded ) ? array_filter( $decoded, 'is_array' ) : array();
	}
}
