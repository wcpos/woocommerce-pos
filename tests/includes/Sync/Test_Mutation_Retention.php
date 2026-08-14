<?php
/**
 * Retention tests for the sync mutation store.
 *
 * Settled mutation rows (done/applied) exist to answer idempotent replays; a
 * client's retry horizon is hours, so rows older than the retention window are
 * dead weight and must be pruned by the daily purge cron. Failure rows
 * (poison/blocked) are manual-recovery records: kept forever by default,
 * prunable via an opt-in filter.
 *
 * Pruning is safe against replays of pruned mutations because the store is a
 * fast-path, not the only guard: a replayed create is caught by uuid identity
 * resolution, a replayed update by the baseRevision compare, and a replayed
 * delete of a gone record is an idempotent success.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Sync_Journal_Purge;

/**
 * Mutation store retention behavior.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Mutation_Store
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal_Purge
 */
class Test_Mutation_Retention extends Sync_Store_Test_Case {
	/**
	 * The store under test.
	 *
	 * @var Mutation_Store
	 */
	private $store;

	/**
	 * Install a clean mutation table.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->store = new Mutation_Store();
		wp_clear_scheduled_hook( Sync_Journal_Purge::PURGE_HOOK );
	}

	/**
	 * Remove retention filters and cron state added by individual tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_sync_mutation_settled_retention_days' );
		remove_all_filters( 'woocommerce_pos_sync_mutation_create_retention_days' );
		remove_all_filters( 'woocommerce_pos_sync_mutation_failure_retention_days' );
		wp_clear_scheduled_hook( Sync_Journal_Purge::PURGE_HOOK );
		parent::tearDown();
	}

	/**
	 * Seed one mutation row in a given status with a given age.
	 *
	 * Uses the store's own transitions where possible; the age is backdated
	 * with direct SQL because created_at is not injectable (by design).
	 *
	 * @param string $mutation_id Unique mutation id.
	 * @param string $status      Target status: pending|applied|done|poison|blocked.
	 * @param int    $age_days    Age of the row, in days.
	 * @param string $operation   Mutation operation (create rows use the longer window).
	 */
	private function seed_mutation( string $mutation_id, string $status, int $age_days, string $operation = 'update' ): void {
		global $wpdb;

		$this->store->reserve( 'products', $mutation_id, 'uuid-' . $mutation_id, $operation );
		switch ( $status ) {
			case 'pending':
				break;
			case 'applied':
				$this->store->mark_applied( $mutation_id, 42, 200 );
				break;
			case 'done':
				$this->store->mark_applied( $mutation_id, 42, 200 );
				$this->store->finalize( $mutation_id, 42 );
				break;
			case 'poison':
				$this->store->mark_poison( $mutation_id, 42 );
				break;
			case 'blocked':
				$this->store->mark_indeterminate( $mutation_id, 42, 500 );
				break;
			default:
				$this->fail( "Unknown status {$status}" );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->store->table_name()} SET created_at = %s WHERE mutation_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table name.
				gmdate( 'Y-m-d H:i:s', time() - $age_days * DAY_IN_SECONDS ),
				$mutation_id
			)
		);
	}

	/**
	 * Count rows currently in the mutation table.
	 */
	private function count_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table name.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->store->table_name()}" );
	}

	/**
	 * Old settled rows (done/applied) are deleted; every other status survives.
	 */
	public function test_prune_settled_deletes_old_done_and_applied_rows_only(): void {
		// Arrange.
		$this->seed_mutation( 'old-done', 'done', 10 );
		$this->seed_mutation( 'old-applied', 'applied', 10 );
		$this->seed_mutation( 'new-done', 'done', 1 );
		$this->seed_mutation( 'old-pending', 'pending', 10 );
		$this->seed_mutation( 'old-poison', 'poison', 10 );
		$this->seed_mutation( 'old-blocked', 'blocked', 10 );

		// Act.
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$deleted = $this->store->prune_settled( $cutoff, $cutoff, 100 );

		// Assert.
		$this->assertSame( 2, $deleted, 'Exactly the two old settled rows should be deleted' );
		$this->assertNull( $this->store->lookup( 'products', 'old-done' ) );
		$this->assertNull( $this->store->lookup( 'products', 'old-applied' ) );
		$this->assertNotNull( $this->store->lookup( 'products', 'new-done' ), 'Rows inside the window must survive' );
		$this->assertNotNull( $this->store->lookup( 'products', 'old-pending' ), 'Pending rows are the reservation lane, never retention-pruned' );
		$this->assertNotNull( $this->store->lookup( 'products', 'old-poison' ), 'Failure rows are not settled rows' );
		$this->assertNotNull( $this->store->lookup( 'products', 'old-blocked' ), 'Failure rows are not settled rows' );
	}

	/**
	 * The settled prune respects the batch limit and reports what it deleted.
	 */
	public function test_prune_settled_respects_batch_limit(): void {
		// Arrange.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_mutation( "old-{$i}", 'done', 10 );
		}

		// Act + Assert.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->assertSame( 2, $this->store->prune_settled( $cutoff, $cutoff, 2 ) );
		$this->assertSame( 3, $this->count_rows() );
		$this->assertSame( 3, $this->store->prune_settled( $cutoff, $cutoff, 100 ) );
		$this->assertSame( 0, $this->count_rows() );
	}

	/**
	 * Old failure rows (poison/blocked) are deleted; other statuses survive.
	 */
	public function test_prune_failed_deletes_old_failure_rows_only(): void {
		// Arrange.
		$this->seed_mutation( 'old-poison', 'poison', 100 );
		$this->seed_mutation( 'old-blocked', 'blocked', 100 );
		$this->seed_mutation( 'new-poison', 'poison', 10 );
		$this->seed_mutation( 'old-done', 'done', 100 );

		// Act.
		$deleted = $this->store->prune_failed( gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ), 100 );

		// Assert.
		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->store->lookup( 'products', 'old-poison' ) );
		$this->assertNull( $this->store->lookup( 'products', 'old-blocked' ) );
		$this->assertNotNull( $this->store->lookup( 'products', 'new-poison' ) );
		$this->assertNotNull( $this->store->lookup( 'products', 'old-done' ), 'Settled rows are not failure rows' );
	}

	/**
	 * The final delete must not remove a new pending reservation that reused a
	 * mutation id after another worker deleted the selected retention row.
	 *
	 * @dataProvider provide_prune_race_cases
	 *
	 * @param string $mutation_id Mutation id used by the race.
	 * @param string $status      Initially eligible status.
	 * @param string $method      Prune method under test.
	 */
	public function test_prune_rechecks_eligibility_before_deleting( string $mutation_id, string $status, string $method ): void {
		$this->seed_mutation( $mutation_id, $status, 100 );
		$interleaved = false;
		$filter      = function ( string $query ) use ( &$filter, &$interleaved, $mutation_id ): string {
			if ( false !== strpos( $query, "DELETE FROM {$this->store->table_name()}" ) && false !== strpos( $query, $mutation_id ) ) {
				remove_filter( 'query', $filter );
				global $wpdb;
				$wpdb->delete( $this->store->table_name(), array( 'mutation_id' => $mutation_id ) );
				$interleaved = $this->store->reserve( 'products', $mutation_id, 'replacement-uuid', 'update' );
			}

			return $query;
		};

		add_filter( 'query', $filter );
		try {
			$future  = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			$deleted = 'prune_settled' === $method
				? $this->store->prune_settled( $future, $future, 1 )
				: $this->store->prune_failed( $future, 1 );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertTrue( $interleaved, 'The selected row should be replaced before the final delete' );
		$this->assertSame( 0, $deleted, 'The replacement pending reservation must not be retention-pruned' );
		$this->assertSame( 'pending', $this->store->lookup( 'products', $mutation_id )['status'] ?? null );
	}

	/**
	 * Retention paths that must re-check eligibility in the final delete.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function provide_prune_race_cases(): array {
		return array(
			'settled row' => array( 'race-settled', 'done', 'prune_settled' ),
			'failure row' => array( 'race-failure', 'poison', 'prune_failed' ),
		);
	}

	/**
	 * Creates get a longer window than other settled rows: the mutation row
	 * is the only replay guard for a create whose record was later deleted.
	 */
	public function test_prune_settled_keeps_creates_for_the_longer_create_window(): void {
		// Arrange.
		$this->seed_mutation( 'mid-create', 'done', 30, 'create' );
		$this->seed_mutation( 'old-create', 'done', 100, 'create' );
		$this->seed_mutation( 'mid-update', 'done', 30 );

		// Act: 7-day settled cutoff, 90-day create cutoff.
		$deleted = $this->store->prune_settled(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ),
			100
		);

		// Assert.
		$this->assertSame( 2, $deleted );
		$this->assertNotNull( $this->store->lookup( 'products', 'mid-create' ), 'Creates inside the create window must survive the settled cutoff' );
		$this->assertNull( $this->store->lookup( 'products', 'old-create' ), 'Creates past the create window are pruned' );
		$this->assertNull( $this->store->lookup( 'products', 'mid-update' ), 'Non-creates use the settled cutoff' );
	}

	/**
	 * The daily purge prunes settled mutations older than the default 7-day
	 * window and keeps failure rows.
	 */
	public function test_purge_expired_prunes_old_settled_mutations_by_default(): void {
		// Arrange.
		$this->seed_mutation( 'old-done', 'done', 8 );
		$this->seed_mutation( 'new-done', 'done', 6 );
		$this->seed_mutation( 'aging-create', 'done', 30, 'create' );
		$this->seed_mutation( 'ancient-create', 'done', 100, 'create' );
		$this->seed_mutation( 'old-poison', 'poison', 400 );
		$this->seed_mutation( 'old-blocked', 'blocked', 400 );

		// Act.
		( new Sync_Journal_Purge() )->purge_expired();

		// Assert.
		$this->assertNull( $this->store->lookup( 'products', 'old-done' ), 'Settled rows past the window should be pruned by the cron' );
		$this->assertNotNull( $this->store->lookup( 'products', 'new-done' ), 'Settled rows inside the window must survive' );
		$this->assertNotNull( $this->store->lookup( 'products', 'aging-create' ), 'Creates inside the default 90-day create window must survive' );
		$this->assertNull( $this->store->lookup( 'products', 'ancient-create' ), 'Creates past the default 90-day create window are pruned' );
		$this->assertNotNull( $this->store->lookup( 'products', 'old-poison' ), 'Failure rows are kept forever by default' );
		$this->assertNotNull( $this->store->lookup( 'products', 'old-blocked' ), 'Failure rows are kept forever by default' );
	}

	/**
	 * Setting the mutation retention filter to zero disables settled pruning.
	 */
	public function test_purge_expired_mutation_retention_zero_disables_pruning(): void {
		// Arrange.
		add_filter( 'woocommerce_pos_sync_mutation_settled_retention_days', '__return_zero' );
		$this->seed_mutation( 'old-done', 'done', 400 );

		// Act.
		( new Sync_Journal_Purge() )->purge_expired();

		// Assert.
		$this->assertNotNull( $this->store->lookup( 'products', 'old-done' ), 'Retention 0 must disable settled pruning' );
	}

	/**
	 * Opting into failure retention prunes old poison/blocked rows.
	 */
	public function test_purge_expired_failure_retention_optin_prunes_old_failures(): void {
		// Arrange.
		add_filter(
			'woocommerce_pos_sync_mutation_failure_retention_days',
			static function (): int {
				return 30;
			}
		);
		$this->seed_mutation( 'old-poison', 'poison', 40 );
		$this->seed_mutation( 'new-poison', 'poison', 20 );

		// Act.
		( new Sync_Journal_Purge() )->purge_expired();

		// Assert.
		$this->assertNull( $this->store->lookup( 'products', 'old-poison' ), 'Failures past the opt-in window should be pruned' );
		$this->assertNotNull( $this->store->lookup( 'products', 'new-poison' ), 'Failures inside the opt-in window must survive' );
	}
}
