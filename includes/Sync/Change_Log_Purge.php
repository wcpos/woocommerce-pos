<?php
/**
 * WCPOS change-log retention service.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Compacts superseded changes and expires old tombstones in bounded batches.
 *
 * Pure policy: windows, batching, caps, and scheduling. All table SQL lives
 * on Change_Log. Compaction is lossless (only superseded rows die); tombstone
 * pruning is the one lossy operation and advances the log's prune watermark
 * so clients can detect a pruned interval.
 */
final class Change_Log_Purge {
	/** Daily cron hook that purges retained change-log rows. */
	const PURGE_HOOK = 'wcpos_change_log_purge';

	/** Hard ceiling on deletions per run across both operations. */
	const MAX_DELETES_PER_RUN = 5000;

	/**
	 * Change log being purged.
	 *
	 * @var Change_Log
	 */
	private $change_log;

	/**
	 * Side-effect-free constructor.
	 *
	 * @param Change_Log|null $change_log Change log to purge.
	 */
	public function __construct( ?Change_Log $change_log = null ) {
		$this->change_log = $change_log ?? new Change_Log();
	}

	/**
	 * Register the cron callback and ensure the daily event is scheduled.
	 */
	public function register_hooks(): void {
		add_action( self::PURGE_HOOK, array( __CLASS__, 'run_purge' ) );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Cron entry point for change-log retention.
	 */
	public static function run_purge(): void {
		( new self() )->purge_expired();
	}

	/**
	 * Purge eligible rows, capped per run; a capped run reschedules itself.
	 */
	public function purge_expired(): void {
		$batch = max( 1, min( self::MAX_DELETES_PER_RUN, (int) apply_filters( 'woocommerce_pos_change_log_purge_batch_size', 500 ) ) );
		$now   = time();

		$compaction_hours  = max( 0, (int) apply_filters( 'woocommerce_pos_change_log_compaction_window_hours', 24 ) );
		$compaction_gmt    = gmdate( 'Y-m-d H:i:s', $now - $compaction_hours * HOUR_IN_SECONDS );
		$compaction_cutoff = $this->change_log->sequence_at_or_before( $compaction_gmt );

		$tombstone_days = (int) apply_filters( 'woocommerce_pos_change_log_tombstone_retention_days', 90 );
		$tombstone_gmt  = gmdate( 'Y-m-d H:i:s', $now - $tombstone_days * DAY_IN_SECONDS );
		// Never prune the newest row: head_sequence() is MAX(sequence), so an
		// idle store whose last event is an old tombstone would otherwise see
		// its head regress (and MySQL 5.7 reuses AUTO_INCREMENT after restart).
		$tombstone_cutoff = $tombstone_days > 0
			? min( $this->change_log->sequence_at_or_before( $tombstone_gmt ), $this->change_log->head_sequence() - 1 )
			: 0;

		$deleted = 0;
		do {
			$limit = min( $batch, self::MAX_DELETES_PER_RUN - $deleted );
			if ( $limit <= 0 ) {
				break;
			}
			$count    = $this->change_log->compact( $compaction_cutoff, $compaction_gmt, $limit );
			$deleted += $count;
		} while ( $count === $limit && $deleted < self::MAX_DELETES_PER_RUN );

		if ( $tombstone_days > 0 ) {
			do {
				$limit = min( $batch, self::MAX_DELETES_PER_RUN - $deleted );
				if ( $limit <= 0 ) {
					break;
				}
				$result = $this->change_log->prune_tombstones( $tombstone_cutoff, $tombstone_gmt, $limit );
				$count  = $result['deleted'];
				$deleted += $count;
			} while ( $count === $limit && $deleted < self::MAX_DELETES_PER_RUN );
		}

		// A capped run means backlog remains — drain it across bounded runs
		// rather than waiting a day. WP dedupes identical single events
		// scheduled within ten minutes, so this cannot stack.
		if ( $deleted >= self::MAX_DELETES_PER_RUN ) {
			wp_schedule_single_event( $now + 5 * MINUTE_IN_SECONDS, self::PURGE_HOOK );
		}
	}
}
