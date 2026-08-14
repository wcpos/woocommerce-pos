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
 * on Sync_Journal and Mutation_Store. Compaction is lossless (only superseded
 * rows die); tombstone pruning is the one lossy operation and advances the
 * log's prune watermark so clients can detect a pruned interval. The same
 * daily run also expires settled mutation rows past their replay window (see
 * Mutation_Store::prune_settled()).
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
	 * Slice of the ceiling reserved for mutation-store expiry, for the same
	 * reason pruning has a floor: journal work runs first and a saturated
	 * journal (≥ the full ceiling every 5-minute reschedule) would otherwise
	 * starve mutation expiry indefinitely — regrowing the exact table this
	 * expiry exists to bound.
	 */
	const MIN_MUTATION_DELETES_PER_RUN = 500;

	/** Default retention for settled (done/applied) non-create mutation rows. */
	const DEFAULT_SETTLED_RETENTION_DAYS = 7;

	/**
	 * Default retention for settled CREATE mutation rows. Longer than the
	 * general settled window because the mutation row is the only replay
	 * guard for a create whose record was later deleted server-side (see
	 * Mutation_Store::prune_settled()).
	 */
	const DEFAULT_CREATE_RETENTION_DAYS = 90;

	/** Default retention for failure (poison/blocked) rows: 0 = keep forever. */
	const DEFAULT_FAILURE_RETENTION_DAYS = 0;

	/**
	 * Change log being purged.
	 *
	 * @var Sync_Journal
	 */
	private $sync_journal;

	/**
	 * Mutation store whose settled rows are expired by the same run.
	 *
	 * @var Mutation_Store
	 */
	private $mutation_store;

	/**
	 * Side-effect-free constructor.
	 *
	 * @param Sync_Journal|null   $sync_journal   Change log to purge.
	 * @param Mutation_Store|null $mutation_store Mutation store to expire.
	 */
	public function __construct( ?Sync_Journal $sync_journal = null, ?Mutation_Store $mutation_store = null ) {
		$this->sync_journal   = $sync_journal ?? new Sync_Journal();
		$this->mutation_store = $mutation_store ?? new Mutation_Store();
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
		// Never let ANY served stream's head regress: clamp below the newest row
		// of each non-empty stream, not merely the global head. Heads are
		// stream-scoped — the newest ORDER row can be an expired tombstone while
		// newer catalogue rows hold the global head above it; pruning it would
		// drop the order-lane head below live client checkpoints (whose
		// cursor-past-head guard then forces a full resync), and vice versa.
		// This also preserves the original rationale: an idle store whose last
		// event is an old tombstone must not see a head regress, and MySQL 5.7
		// reuses AUTO_INCREMENT after restart.
		$tombstone_cutoff = 0;
		if ( $tombstone_days > 0 ) {
			$tombstone_cutoff = $this->sync_journal->sequence_at_or_before( $tombstone_gmt );
			foreach ( array( array( 'order' ), Sync_Journal::catalogue_object_types() ) as $stream_types ) {
				$stream_head = $this->sync_journal->head_sequence( $stream_types );
				if ( $stream_head > 0 ) {
					$tombstone_cutoff = min( $tombstone_cutoff, $stream_head - 1 );
				}
			}
		}

		$pruning_active = $tombstone_days > 0;

		// Mutation retention windows. The create window never drops below the
		// general settled window — creates are the riskier class to prune.
		$settled_days = max( 0, (int) apply_filters( 'woocommerce_pos_sync_mutation_settled_retention_days', self::DEFAULT_SETTLED_RETENTION_DAYS ) );
		$create_days  = max( $settled_days, (int) apply_filters( 'woocommerce_pos_sync_mutation_create_retention_days', self::DEFAULT_CREATE_RETENTION_DAYS ) );
		$failure_days = max( 0, (int) apply_filters( 'woocommerce_pos_sync_mutation_failure_retention_days', self::DEFAULT_FAILURE_RETENTION_DAYS ) );

		// Journal work runs first, so each active mutation-expiry phase needs a
		// reserved floor or an earlier saturated phase would starve it every run.
		$settled_floor = $settled_days > 0 ? self::MIN_MUTATION_DELETES_PER_RUN : 0;
		$failure_floor = $failure_days > 0 ? self::MIN_MUTATION_DELETES_PER_RUN : 0;
		$mutation_floor = $settled_floor + $failure_floor;

		$compaction = $this->drain(
			fn ( int $limit ): int => $this->sync_journal->compact( $compaction_cutoff, $compaction_gmt, $limit ),
			$batch,
			self::MAX_DELETES_PER_RUN - ( $pruning_active ? self::MIN_PRUNE_DELETES_PER_RUN : 0 ) - $mutation_floor
		);
		$deleted = $compaction['deleted'];
		$capped  = $compaction['capped'];

		if ( $pruning_active ) {
			$pruning = $this->drain(
				fn ( int $limit ): int => $this->sync_journal->prune_tombstones( $tombstone_cutoff, $tombstone_gmt, $limit )['deleted'],
				$batch,
				self::MAX_DELETES_PER_RUN - $deleted - $mutation_floor
			);
			$deleted += $pruning['deleted'];
			$capped   = $capped || $pruning['capped'];
		}

		// Expire settled mutation rows (done/applied) past their replay window.
		if ( $settled_days > 0 && $deleted < self::MAX_DELETES_PER_RUN ) {
			$settled_gmt = gmdate( 'Y-m-d H:i:s', $now - $settled_days * DAY_IN_SECONDS );
			$create_gmt  = gmdate( 'Y-m-d H:i:s', $now - $create_days * DAY_IN_SECONDS );
			$settled     = $this->drain(
				fn ( int $limit ): int => $this->mutation_store->prune_settled( $settled_gmt, $create_gmt, $limit ),
				$batch,
				self::MAX_DELETES_PER_RUN - $deleted - $failure_floor
			);
			$deleted += $settled['deleted'];
			$capped   = $capped || $settled['capped'];
		}

		// Failure rows (poison/blocked) are manual-recovery records: pruned
		// only when a site opts into a window via this filter (0 = keep forever).
		if ( $failure_days > 0 && $deleted < self::MAX_DELETES_PER_RUN ) {
			$failure_gmt = gmdate( 'Y-m-d H:i:s', $now - $failure_days * DAY_IN_SECONDS );
			$failures    = $this->drain(
				fn ( int $limit ): int => $this->mutation_store->prune_failed( $failure_gmt, $limit ),
				$batch,
				self::MAX_DELETES_PER_RUN - $deleted
			);
			$deleted += $failures['deleted'];
			$capped   = $capped || $failures['capped'];
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
