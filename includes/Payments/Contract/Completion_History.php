<?php
/**
 * The completion history of a sale, read.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;

/**
 * One decode of `_wcpos_completions` (ADR 0040 rule 1c), oldest entry first, and every question
 * asked of it: which completion stands, which one a fiscal record or a command names, which payment
 * rows were counted, how many completions a session holds. The stored shape is known here and in
 * Completion_Stamp, which writes it; money asks only stands() and counted(), never `n`.
 */
final class Completion_History {
	/** Entry field => the write-once till meta key it overrides. */
	private const TILL_FIELDS = array(
		'register'  => '_wcpos_register',
		'session'   => '_wcpos_session',
		'sale_time' => '_wcpos_sale_time',
		'sale_tz'   => '_wcpos_sale_tz',
		'counter'   => '_wcpos_sale_counter',
	);

	/**
	 * The order the history was read from.
	 *
	 * @var WC_Order
	 */
	private $order;

	/**
	 * Entries, oldest first.
	 *
	 * @var array[]
	 */
	private $entries;

	/**
	 * Read.
	 *
	 * @param WC_Order $order   Order.
	 * @param array[]  $entries Entries, oldest first.
	 */
	private function __construct( WC_Order $order, array $entries ) {
		$this->order   = $order;
		$this->entries = $entries;
	}

	/**
	 * The order's history as its meta holds it now.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function of( WC_Order $order ): self {
		$raw     = $order->get_meta( Completion_Stamp::META_KEY, true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return new self( $order, is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array() );
	}

	/**
	 * The ids of the orders whose history names the session in any entry; a candidate set for
	 * in_session(), read straight from the meta table.
	 *
	 * @param string $session_id Session id, as entries store it.
	 * @return int[]
	 * @throws \RuntimeException On read failure.
	 */
	public static function orders_in_session( string $session_id ): array {
		global $wpdb;
		$hpos  = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$key   = $hpos ? 'order_id' : 'post_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Storage-selected identifiers.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$key} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s", Completion_Stamp::META_KEY, '%' . $wpdb->esc_like( '"session":"' . $session_id . '"' ) . '%' ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Session completion read failed.' );
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * The ids of the orders whose latest entry stands, completed between $since and $until, with no
	 * `sale` row in $records naming it and no $parked meta (the parked pass owns those), oldest completion first. The window is read through the
	 * `_wcpos_completed_at` meta key index; the latest entry is the text after the last `{"id":"`
	 * (Completion_Stamp writes the id first and no entry nests an object), and its row is looked up by
	 * the record store's unique (type, source_id) index, so a completion that has its record never
	 * comes back. Its id is compared as ASCII so a records table collated unlike the meta table still compares.
	 *
	 * @param string $records Fiscal record table.
	 * @param string $parked  Meta key of a parked sale document.
	 * @param int    $since   Earliest completion time, a timestamp.
	 * @param int    $until   Latest completion time, a timestamp.
	 * @param int    $limit   Batch cap.
	 * @return int[]
	 * @throws \RuntimeException On read failure.
	 */
	public static function orders_standing_without_a_record( string $records, string $parked, int $since, int $until, int $limit ): array {
		global $wpdb;
		$hpos   = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table  = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$key    = $hpos ? 'order_id' : 'post_id';
		$latest = 'SUBSTRING_INDEX( h.meta_value, %s, -1 )';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Storage-selected and class-owned identifiers; an index lookup, never cached.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.{$key} FROM {$table} c JOIN {$table} h ON h.{$key} = c.{$key} AND h.meta_key = %s"
				. " WHERE c.meta_key = %s AND c.meta_value BETWEEN %s AND %s AND {$latest} LIKE %s"
				. " AND NOT EXISTS ( SELECT 1 FROM {$records} f WHERE f.type = 'sale' AND f.source_id = CONVERT( LEFT( {$latest}, 36 ) USING ascii ) )"
				. " AND NOT EXISTS ( SELECT 1 FROM {$table} p WHERE p.{$key} = c.{$key} AND p.meta_key = %s )"
				. ' ORDER BY c.meta_value LIMIT %d',
				Completion_Stamp::META_KEY,
				Completion_Stamp::COMPLETED_AT_META_KEY,
				gmdate( 'c', $since ),
				gmdate( 'c', $until ),
				'{"id":"',
				'%' . $wpdb->esc_like( '"reopened_at_gmt":null' ) . '%',
				'{"id":"',
				$parked,
				$limit
			)
		);
		// phpcs:enable
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Unrecorded completion read failed.' );
		}
		return array_map( 'intval', $ids );
	}

	/** Every entry, oldest first. */
	public function entries(): array {
		return $this->entries;
	}

	/** Whether the order has no completion entry (it never completed at a register since the history existed). */
	public function is_empty(): bool {
		return array() === $this->entries;
	}

	/** The latest entry, reopened or not; null when there is none. */
	public function latest(): ?array {
		return $this->entries ? $this->entries[ count( $this->entries ) - 1 ] : null;
	}

	/** The latest entry while it stands; null when there is none or it was reopened. */
	public function standing(): ?array {
		$latest = $this->latest();
		return null !== $latest && empty( $latest['reopened_at_gmt'] ) ? $latest : null;
	}

	/**
	 * Whether the sale's completion stands: its latest entry is not marked reopened, or, before the
	 * history existed, it is paid in a completed status. A standing sale takes no new tender, whatever
	 * WooCommerce's is_editable() says of its status (a merchant's POS status stored as on-hold).
	 */
	public function stands(): bool {
		if ( $this->entries ) {
			return null !== $this->standing();
		}
		return null !== $this->order->get_date_paid( 'edit' ) && in_array( $this->order->get_status(), Completion_Stamp::completed_statuses( $this->order ), true );
	}

	/**
	 * Completion n.
	 *
	 * @param int $n Completion number.
	 */
	public function by_n( int $n ): ?array {
		return $this->find( static fn( array $entry ) => (int) ( $entry['n'] ?? 0 ) === $n );
	}

	/**
	 * The completion with this id.
	 *
	 * @param string $id Completion id.
	 */
	public function by_id( string $id ): ?array {
		return $this->find( static fn( array $entry ) => ( $entry['id'] ?? null ) === $id );
	}

	/**
	 * Whether a captured row id was counted by any completion (it then leaves only through a refund).
	 *
	 * @param string $payment_id Payment row id, any case.
	 */
	public function counted( string $payment_id ): bool {
		return null !== $this->find( static fn( array $entry ) => in_array( strtolower( $payment_id ), array_map( 'strtolower', (array) ( $entry['payment_ids'] ?? array() ) ), true ) );
	}

	/**
	 * How many completions name the session: a sale reopened and completed again in it counts twice.
	 *
	 * @param string $session_id Session id.
	 */
	public function in_session( string $session_id ): int {
		return count( array_filter( $this->entries, static fn( array $entry ) => ( $entry['session'] ?? null ) === $session_id ) );
	}

	/**
	 * The till tuple the standing completion was taken under: its register, session, sale time, zone
	 * and counter, an absent value null. With nothing standing, the order's write-once till meta.
	 *
	 * @return array{register: ?string, session: ?string, sale_time: ?string, sale_tz: ?string, counter: ?string}
	 */
	public function till(): array {
		$standing = $this->standing();
		$till     = array();
		foreach ( self::TILL_FIELDS as $field => $key ) {
			$value          = null !== $standing ? ( $standing[ $field ] ?? null ) : $this->order->get_meta( $key, true );
			$till[ $field ] = null === $value || '' === $value ? null : (string) $value;
		}
		return $till;
	}

	/**
	 * The last entry that matches.
	 *
	 * @param callable $matches Entry predicate.
	 */
	private function find( callable $matches ): ?array {
		$found = null;
		foreach ( $this->entries as $entry ) {
			if ( $matches( $entry ) ) {
				$found = $entry;
			}
		}
		return $found;
	}
}
