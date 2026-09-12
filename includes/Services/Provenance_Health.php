<?php
/**
 * Read-only till provenance diagnostics.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeImmutable;
use DateTimeZone;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;

/** Reports observed counters and clock skew without correcting orders. */
final class Provenance_Health {
	/** Store health is a daily check; closures own the long history. */
	public const WINDOW_DAYS = 30;
	/** The newest POS-stamped orders in the window keep the query bounded on a big store. */
	public const ORDER_CAP = 5000;
	/** Beyond normal clock drift, ahead clocks and delayed offline sales merit a look. */
	public const SKEW_SECONDS = 600;
	/** Limit order ids per finding so the payload stays small. */
	public const SAMPLE_LIMIT = 20;

	/**
	 * Report the window for the supplied register list, preserving its order.
	 *
	 * @param array      $registers Register_Store::list() rows, already scoped by the caller.
	 * @param array      $known_ids All register ids known to the site, without store or status filtering.
	 * @param array|null $store_ids Authorized order store ids, or null for all stores.
	 * @return array
	 */
	public function report( array $registers, array $known_ids, ?array $store_ids = null ): array {
		$orders = array();
		foreach ( $this->provenance_rows() as $row ) {
			$orders[ (int) $row['order_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}
		$groups = array();
		$sessions = array();
		$store_ids = null === $store_ids ? null : array_map( 'strval', $store_ids );
		foreach ( $orders as $id => $meta ) {
			if ( null !== $store_ids && ! in_array( $meta['_pos_store'] ?? '', $store_ids, true ) ) {
				continue;
			}
			$session = strtolower( $meta['_wcpos_session'] ?? '' );
			if ( '' !== $session ) {
				$sessions[ $session ][] = $id;
			}
			$register = strtolower( $meta['_wcpos_register'] ?? '' );
			if ( '' !== $register ) {
				$groups[ $register ][ $id ] = $meta;
			}
		}
		$report = array(
			'window_days' => self::WINDOW_DAYS,
			'skew_seconds' => self::SKEW_SECONDS,
			'truncated' => count( $orders ) === self::ORDER_CAP,
			'registers' => array(),
			'unregistered' => array(),
			'unknown_sessions' => array(),
		);
		foreach ( $registers as $register ) {
			$report['registers'][] = $this->register_report( $register, $groups[ $register['id'] ] ?? array() ) + ( new Closure_Store() )->health( $register['id'] );
		}
		foreach ( array_diff_key( $groups, array_flip( $known_ids ) ) as $register => $group ) {
			$ids = array_slice( array_keys( $group ), 0, self::SAMPLE_LIMIT );
			$report['unregistered'][] = array(
				'register_id' => $register,
				'orders' => count( $group ),
				'order_ids' => $ids,
				'order_numbers' => array_map( array( $this, 'order_number' ), $ids ),
			);
		}
		$session_store = new Register_Session_Store();
		foreach ( $sessions as $id => $order_ids ) {
			if ( ! $session_store->get( $id ) ) {
				$report['unknown_sessions'][] = array(
					'session_id' => $id,
					'orders' => count( $order_ids ),
					'order_ids' => array_slice( $order_ids, 0, self::SAMPLE_LIMIT ),
				);
			}
		}
		return $report;
	}

	/**
	 * Fetch provenance meta keys in one query, capping POS orders before joining their meta.
	 *
	 * @return array
	 * @throws \RuntimeException When the diagnostic query fails.
	 */
	private function provenance_rows(): array {
		global $wpdb;
		$hpos = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
		$meta = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$foreign_key = $hpos ? 'order_id' : 'post_id';
		$date = $hpos ? 'date_created_gmt' : 'post_date_gmt';
		$type = $hpos ? 'type' : 'post_type';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are chosen above, never supplied by a request.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.{$foreign_key} AS order_id, m.meta_key, m.meta_value
				FROM (SELECT o.id FROM {$orders} o
					WHERE o.{$type} = 'shop_order' AND o.{$date} >= %s
					AND EXISTS (SELECT 1 FROM {$meta} stamp
						WHERE stamp.{$foreign_key} = o.id AND stamp.meta_key IN ('_wcpos_register', '_wcpos_session'))
					ORDER BY o.{$date} DESC, o.id DESC LIMIT %d) recent
				INNER JOIN {$meta} m ON m.{$foreign_key} = recent.id
				WHERE m.meta_key IN ('_wcpos_session', '_wcpos_register', '_wcpos_till', '_wcpos_sale_counter', '_wcpos_sale_time', '_wcpos_sale_received_gmt', '_pos_store')
				ORDER BY recent.id DESC",
				gmdate( 'Y-m-d H:i:s', time() - self::WINDOW_DAYS * DAY_IN_SECONDS ),
				self::ORDER_CAP
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Provenance health query failed.' );
		}
		return $rows;
	}

	/**
	 * Summarize counters and skew for one known register.
	 *
	 * @param array $register Register row.
	 * @param array $orders Pivoted meta by order id.
	 * @return array
	 */
	private function register_report( array $register, array $orders ): array {
		$result = array(
			'id' => $register['id'],
			'name' => $register['name'],
			'orders' => count( $orders ),
			'first_counter' => null,
			'last_counter' => null,
			'gaps' => array(),
			'duplicates' => array(),
			'skew' => array(),
		);
		$tills = array();
		foreach ( $orders as $id => $meta ) {
			$value = $meta['_wcpos_sale_counter'] ?? '';
			$counter = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_counter', $value ) && false !== $counter ) {
				$till_value = $meta['_wcpos_till'] ?? '';
				$till       = Pos_Order_Audit::is_valid_till_value( '_wcpos_till', $till_value )
					? strtolower( (string) $till_value )
					: $register['id'];
				$tills[ $till ][ $counter ][] = $id;
			}
			$skew = $this->order_skew( $id, $meta );
			if ( null !== $skew ) {
				$result['skew'][] = $skew;
			}
		}
		foreach ( $tills as $till => $counters ) {
			ksort( $counters, SORT_NUMERIC );
			$previous = null;
			foreach ( $counters as $counter => $ids ) {
				if ( null === $previous ) {
					$result['first_counter'] = null === $result['first_counter'] ? $counter : min( $result['first_counter'], $counter );
				} elseif ( $counter - $previous > 1 ) {
					$result['gaps'][] = array(
						'till' => $till,
						'after' => $previous,
						'before' => $counter,
						'missing' => $counter - $previous - 1,
					);
				}
				if ( count( $ids ) > 1 ) {
					$ids = array_slice( $ids, 0, self::SAMPLE_LIMIT );
					$result['duplicates'][] = array(
						'till' => $till,
						'counter' => $counter,
						'order_ids' => $ids,
						'order_numbers' => array_map( array( $this, 'order_number' ), $ids ),
					);
				}
				$result['last_counter'] = max( $result['last_counter'] ?? 0, $counter );
				$previous = $counter;
			}
		}
		usort(
			$result['skew'],
			static function ( $a, $b ) {
				return abs( $b['skew_seconds'] ) <=> abs( $a['skew_seconds'] );
			}
		);
		$result['skew'] = array_slice( $result['skew'], 0, self::SAMPLE_LIMIT );
		foreach ( $result['skew'] as &$skew ) {
			$skew['order_number'] = $this->order_number( $skew['order_id'] );
		}
		return $result;
	}

	/**
	 * Compare the offset sale timestamp with the writer's UTC receipt stamp.
	 *
	 * @param int   $id Order id.
	 * @param array $meta Stored provenance.
	 * @return array|null
	 */
	private function order_skew( int $id, array $meta ): ?array {
		$sale = $meta['_wcpos_sale_time'] ?? '';
		$received = $meta['_wcpos_sale_received_gmt'] ?? '';
		if ( ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $sale ) ) {
			return null;
		}
		$receipt = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $received, new DateTimeZone( 'UTC' ) );
		if ( false === $receipt || $receipt->format( 'Y-m-d\TH:i:s\Z' ) !== $received ) {
			return null;
		}
		$seconds = ( new DateTimeImmutable( $sale ) )->getTimestamp() - $receipt->getTimestamp();
		if ( abs( $seconds ) <= self::SKEW_SECONDS ) {
			return null;
		}
		return array(
			'order_id' => $id,
			'order_number' => '',
			'sale_time' => $sale,
			'received_gmt' => $received,
			'skew_seconds' => $seconds,
			'direction' => $seconds < 0 ? 'behind' : 'ahead',
		);
	}

	/**
	 * Resolve display numbers only after sampling; a deleted order has no number.
	 *
	 * @param int $id Sampled order id.
	 * @return string
	 */
	private function order_number( int $id ): string {
		$order = wc_get_order( $id );
		return $order ? (string) $order->get_order_number() : '';
	}
}
