<?php
/**
 * Retire order-meta receipt snapshots in bounded background batches.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use RuntimeException;
use WCPOS\WooCommercePOS\Logger;

/** Deletes only the retired keys and complete snapshot notes, never saves orders. */
final class Receipt_Snapshot_Cleanup {
	const OPTION = 'wcpos_receipt_snapshot_cleanup';
	const HOOK   = 'wcpos_receipt_snapshot_cleanup';
	// Bound each table's work so a large store drains across background requests.
	const BATCH = 1000;
	const META_KEYS = array(
		'_wcpos_receipt_snapshot',
		'_wcpos_receipt_snapshot_checksum',
		'_wcpos_receipt_sequence',
		'_wcpos_receipt_snapshot_created_at_gmt',
		'_wcpos_receipt_submission_status',
		'_wcpos_receipt_submission_status_updated_at',
	);
	const NOTE_FORMAT = 'POS fiscal receipt snapshot created (receipt #%d).';

	/** Register on every request, independent of the sync schema latch. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/** Queue one pending run, including when a previous schedule was lost. */
	public static function arm(): void {
		if ( 'done' === get_option( self::OPTION ) ) {
			return;
		}
		add_option( self::OPTION, 'pending', '', true );
		if ( function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			// Only pending actions count: the currently running action must re-arm.
			$pending = as_get_scheduled_actions(
				array(
					'hook' => self::HOOK,
					'group' => 'wcpos',
					'status' => 'pending',
					'per_page' => 1,
				),
				'ids'
			);
			if ( ! $pending ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK, array(), 'wcpos' );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Delete a bounded batch; an empty run confirms completion.
	 *
	 * @param int $batch Maximum rows per table.
	 * @return int Candidate rows found.
	 * @throws RuntimeException Caught locally to leave failed database work pending.
	 */
	public static function run( int $batch = self::BATCH ): int {
		global $wpdb;
		$found = 0;
		$batch = max( 1, $batch );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Existence check also covers posts-only sites.
			$hpos_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught and logged.
			}
			if ( $hpos_table ) {
				$found += self::delete_meta_batch( $wpdb->prefix . 'wc_orders_meta', $batch );
			}
			$found += self::delete_meta_batch( $wpdb->postmeta, $batch );
			$found += self::delete_notes_batch( $batch );
		} catch ( RuntimeException $e ) {
			// A failed query is not an empty table. Let admin_init re-arm the work.
			Logger::log( 'Receipt snapshot cleanup: ' . $e->getMessage() );
			return $found;
		}
		if ( 0 === $found ) {
			self::finish();
		} else {
			self::arm();
		}
		return $found;
	}

	/** Remove the obsolete counter only once every table is drained. */
	public static function finish(): void {
		delete_option( 'wcpos_receipt_sequence_counter' );
		update_option( self::OPTION, 'done', true );
	}

	/**
	 * Delete by metadata row ID, then invalidate the touched order caches.
	 *
	 * @param string $table One of the two order-meta tables.
	 * @param int    $batch Maximum rows.
	 * @return int Candidate rows found.
	 * @throws RuntimeException On a failed query.
	 */
	private static function delete_meta_batch( string $table, int $batch ): int {
		global $wpdb;
		$hpos = $table === $wpdb->prefix . 'wc_orders_meta';
		$id = $hpos ? 'id' : 'meta_id';
		$owner = $hpos ? 'order_id' : 'post_id';
		$keys = implode( ', ', array_fill( 0, count( self::META_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table/columns; keys and limit are prepared. Caches invalidated below.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$id} AS row_id, {$owner} AS order_id FROM {$table} WHERE meta_key IN ({$keys}) LIMIT %d", array_merge( self::META_KEYS, array( $batch ) ) ) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught and logged, never rendered.
		}
		if ( ! $rows ) {
			return 0;
		}
		$ids = implode( ',', array_map( 'absint', wp_list_pluck( $rows, 'row_id' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers; repeat the exact key constraint at deletion time.
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$id} IN ({$ids}) AND meta_key IN ({$keys})", self::META_KEYS ) );
		if ( false === $removed ) {
			throw new RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught and logged, never rendered.
		}
		$order_ids = array_map( 'intval', array_unique( wp_list_pluck( $rows, 'order_id' ) ) );
		if ( $hpos && method_exists( OrdersTableDataStore::class, 'clear_cached_data' ) ) {
			wc_get_container()->get( OrdersTableDataStore::class )->clear_cached_data( $order_ids );
		}
		foreach ( $order_ids as $order_id ) {
			wp_cache_delete( \WC_Order::generate_meta_cache_key( $order_id, 'orders' ), 'orders' );
			if ( $hpos && class_exists( OrderCache::class ) ) {
				wc_get_container()->get( OrderCache::class )->remove( (int) $order_id );
			} else {
				wp_cache_delete( (int) $order_id, 'post_meta' );
			}
		}
		return count( $rows );
	}

	/**
	 * Remove only complete numbered snapshot notes in English or the site locale.
	 *
	 * @param int $batch Maximum notes.
	 * @return int Candidate notes found.
	 * @throws RuntimeException On a failed query.
	 */
	private static function delete_notes_batch( int $batch ): int {
		global $wpdb;
		$switched = switch_to_locale( get_locale() );
		/* translators: %d: receipt sequence number. */
		$translated = __( 'POS fiscal receipt snapshot created (receipt #%d).', 'woocommerce-pos' );
		if ( $switched ) {
			restore_previous_locale();
		}
		$patterns = array();
		$likes = array();
		foreach ( array( self::NOTE_FORMAT, $translated ) as $format ) {
			$likes[] = str_replace( '\%d', '%', $wpdb->esc_like( $format ) );
			$patterns[] = '/^' . str_replace( '%d', '[0-9]+', preg_quote( $format, '/' ) ) . '$/u';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded scan; wp_delete_comment invalidates comment caches.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID, comment_content FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND ( comment_content LIKE %s OR comment_content LIKE %s ) LIMIT %d", $likes[0], $likes[1], $batch ) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught and logged, never rendered.
		}
		// Only exact matches count as found: a note the LIKE admits but the pattern
		// rejects is not ours and must not keep the job pending forever.
		$found = 0;
		wp_defer_comment_counting( true );
		try {
			foreach ( $rows as $row ) {
				if ( ! preg_match( $patterns[0], $row->comment_content ) && ! preg_match( $patterns[1], $row->comment_content ) ) {
					continue;
				}
				++$found;
				// A note already deleted by another process is still one we found.
				wp_delete_comment( (int) $row->comment_ID, true );
			}
		} finally {
			wp_defer_comment_counting( false );
		}
		return $found;
	}
}
