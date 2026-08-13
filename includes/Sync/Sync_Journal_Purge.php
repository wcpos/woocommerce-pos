<?php
/**
 * WCPOS sync-journal retention service.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Compacts superseded changes and expires old tombstones in bounded batches.
 *
 * Pure policy: windows, batching, caps, and scheduling. All table SQL lives
 * on Sync_Journal. Compaction is lossless (only superseded rows die); tombstone
 * pruning is the one lossy operation and advances the log's prune watermark
 * so clients can detect a pruned interval.
 */
final class Sync_Journal_Purge {
	/** Daily cron hook that purges retained sync-journal rows. */
	const PURGE_HOOK = 'wcpos_sync_journal_purge';

	/** Hard ceiling on deletions per run across both operations. */
	const MAX_DELETES_PER_RUN = 5000;

	/**
	 * Slice of that ceiling that compaction may never take.
	 *
	 * Compaction runs first and a busy store can supply an unbounded backlog of
	 * superseded rows, so a single shared budget lets compaction starve pruning
	 * forever — and pruning is the only operation that advances the prune
	 * watermark. Pruning still gets whatever compaction leaves unused, so this
	 * is a floor for pruning, not a cap.
	 */
	const MIN_PRUNE_DELETES_PER_RUN = 1000;

	/**
	 * Change log being purged.
	 *
	 * @var Sync_Journal
	 */
	private $sync_journal;

	/**
	 * Side-effect-free constructor.
	 *
	 * @param Sync_Journal|null $sync_journal Change log to purge.
	 */
	public function __construct( ?Sync_Journal $sync_journal = null ) {
		$this->sync_journal = $sync_journal ?? new Sync_Journal();
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
	 * Cron entry point for sync-journal retention.
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
		$compaction_cutoff = $this->sync_journal->sequence_at_or_before( $compaction_gmt );

		$tombstone_days = (int) apply_filters( 'woocommerce_pos_change_log_tombstone_retention_days', 90 );
		$tombstone_gmt  = gmdate( 'Y-m-d H:i:s', $now - $tombstone_days * DAY_IN_SECONDS );
		// Never prune the newest row: head_sequence() is MAX(sequence), so an
		// idle store whose last event is an old tombstone would otherwise see
		// its head regress (and MySQL 5.7 reuses AUTO_INCREMENT after restart).
		$tombstone_cutoff = $tombstone_days > 0
			? min( $this->sync_journal->sequence_at_or_before( $tombstone_gmt ), $this->sync_journal->head_sequence() - 1 )
			: 0;

		$sync_journal   = $this->sync_journal;
		$pruning_active = $tombstone_days > 0;

		$compaction = $this->drain(
			static function ( int $limit ) use ( $sync_journal, $compaction_cutoff, $compaction_gmt ): int {
				return $sync_journal->compact( $compaction_cutoff, $compaction_gmt, $limit );
			},
			$batch,
			$pruning_active ? self::MAX_DELETES_PER_RUN - self::MIN_PRUNE_DELETES_PER_RUN : self::MAX_DELETES_PER_RUN
		);
		$deleted = $compaction['deleted'];
		$capped  = $compaction['capped'];

		if ( $pruning_active ) {
			$pruning = $this->drain(
				static function ( int $limit ) use ( $sync_journal, $tombstone_cutoff, $tombstone_gmt ): int {
					$result = $sync_journal->prune_tombstones( $tombstone_cutoff, $tombstone_gmt, $limit );

					return $result['deleted'];
				},
				$batch,
				self::MAX_DELETES_PER_RUN - $deleted
			);
			$deleted += $pruning['deleted'];
			$capped   = $capped || $pruning['capped'];
		}

		// A capped run means backlog remains — drain it across bounded runs
		// rather than waiting a day. WP dedupes identical single events
		// scheduled within ten minutes, so this cannot stack.
		if ( $capped ) {
			wp_schedule_single_event( $now + 5 * MINUTE_IN_SECONDS, self::PURGE_HOOK );
		}
	}

	/**
	 * Delete in batches until one operation runs dry or spends its ceiling.
	 *
	 * @param callable $delete_batch Receives a row limit, returns rows deleted.
	 * @param int      $batch        Rows to delete per call.
	 * @param int      $ceiling      Rows this operation may delete in total.
	 *
	 * @return array{deleted: int, capped: bool} Rows deleted, and whether the ceiling stopped it.
	 */
	private function drain( callable $delete_batch, int $batch, int $ceiling ): array {
		$deleted = 0;
		while ( $deleted < $ceiling ) {
			$limit = min( $batch, $ceiling - $deleted );
			$count = (int) $delete_batch( $limit );
			$deleted += $count;

			if ( $count < $limit ) {
				return array(
					'deleted' => $deleted,
					'capped'  => false,
				);
			}
		}

		return array(
			'deleted' => $deleted,
			'capped'  => $ceiling > 0,
		);
	}
}
