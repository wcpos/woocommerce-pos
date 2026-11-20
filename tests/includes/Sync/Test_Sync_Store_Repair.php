<?php
/**
 * Sync store table repair tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Health;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Store_Repair;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/** Tests repair of missing tables on latched sync stores. */
class Test_Sync_Store_Repair extends Sync_REST_Store_Test_Case {
	/**
	 * Original prefix when using an alternate site.
	 *
	 * @var string
	 */
	private $real_prefix = '';

	/**
	 * Filters installed by the test.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Recorded queries.
	 *
	 * @var string[]
	 */
	private $queries = array();

	/**
	 * Captured log lines.
	 *
	 * @var string[]
	 */
	private $logs = array();

	/** Start with a latched store and an authenticated cashier. */
	public function setUp(): void {
		parent::setUp();
		update_option( Api::SCHEMA_OPTION, Api::SCHEMA_VERSION );
		delete_transient( Store_Repair::ATTEMPT_TRANSIENT );
		delete_option( 'woocommerce_pos_db_upgrade_lock.lock' );
		Logger::reset_dedup_state();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/** Restore the real site and remove test tables and filters. */
	public function tearDown(): void {
		global $wpdb;
		if ( '' !== $this->real_prefix ) {
			$tables       = Health::required_tables();
			$wpdb->prefix = $this->real_prefix;
			foreach ( $tables as $table ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test-owned table name.
			}
		}
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}
		parent::tearDown();
	}

	/**
	 * Use fresh table names, installing only the stores that survived.
	 *
	 * @param string[] $keep Stores to install.
	 */
	private function use_alt_site( array $keep ): void {
		global $wpdb;
		$this->real_prefix = $wpdb->prefix;
		$wpdb->prefix      = $this->real_prefix . 'site5';
		$stores            = array(
			'journal'   => Sync_Journal::class,
			'digest'    => Integrity_Digest::class,
			'mutations' => Mutation_Store::class,
		);
		foreach ( $keep as $store ) {
			( new $stores[ $store ]() )->install();
		}
	}

	/** Record queries without changing them. */
	private function record_queries(): void {
		$recorder = function ( $query ) {
			$this->queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );
		$this->filters[] = array( 'query', $recorder );
	}

	/**
	 * Make the chosen table's CREATE fail after the bootstrap's temporary-table rewrite.
	 *
	 * @param string $table Fully qualified alternate table name.
	 * @return \Closure Query filter.
	 */
	private function fail_create( string $table ): \Closure {
		$rewrite = static function ( $query ) use ( $table ) {
			if ( preg_match( '/^\s*CREATE\s+(TEMPORARY\s+)?TABLE/i', $query ) && false !== strpos( $query, $table ) ) {
				return 'CREATE TABLE ' . $table . ' (id NOT A COLUMN)';
			}
			return $query;
		};
		add_filter( 'query', $rewrite );
		$this->filters[] = array( 'query', $rewrite );
		return $rewrite;
	}

	/** Capture log levels and messages without writing log files. */
	private function capture_logs(): void {
		$capture = function ( $enabled, $message ) {
			$this->logs[] = Logger::$log_level . ' ' . $message;
			return false;
		};
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		$this->filters[] = array( 'wcpos_logging', $capture );
	}

	/** Repairing the digest preserves all journal state and surviving tables. */
	public function test_repair_digest_only_missing_recreates_digest_and_keeps_epoch(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		update_option( Sync_Journal::EPOCH_OPTION, 'known-epoch' );
		$journal = new Sync_Journal();
		$journal->advance_prune_watermark( 'product', 7 );
		$journal->advance_prune_watermark( 'order', 9 );
		$journal->record( 'product', 123, false );
		$backfill = get_option( Sync_Journal::BACKFILL_OPTION );
		$this->record_queries();

		// Act.
		$repaired = Store_Repair::repaired();

		// Assert.
		$this->assertTrue( $repaired );
		$this->assertTrue( Health::is_healthy() );
		$this->assertSame( 'known-epoch', get_option( Sync_Journal::EPOCH_OPTION ) );
		$this->assertSame( '7', get_option( 'wcpos_change_log_prune_watermark_product' ) );
		$this->assertSame( '9', get_option( 'wcpos_change_log_prune_watermark_order' ) );
		$this->assertSame( $backfill, get_option( Sync_Journal::BACKFILL_OPTION ) );
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcpos_sync_journal" ) );
		foreach ( $this->queries as $query ) {
			if ( preg_match( '/^\s*(CREATE|ALTER|DESCRIBE)\b/i', $query ) ) {
				$this->assertStringNotContainsString( $wpdb->prefix . Health::SYNC_JOURNAL_TABLE, $query );
				$this->assertStringNotContainsString( $wpdb->prefix . Health::MUTATIONS_TABLE, $query );
			}
		}
	}

	/** Repairing mutations preserves the epoch and both prune watermarks. */
	public function test_repair_mutations_only_missing_recreates_mutations_and_keeps_epoch(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'digest' ) );
		update_option( Sync_Journal::EPOCH_OPTION, 'known-epoch' );
		$journal = new Sync_Journal();
		$journal->advance_prune_watermark( 'product', 7 );
		$journal->advance_prune_watermark( 'order', 9 );
		$backfill = get_option( Sync_Journal::BACKFILL_OPTION );
		$this->record_queries();

		// Act.
		$repaired = Store_Repair::repaired();

		// Assert.
		$this->assertTrue( $repaired );
		$this->assertTrue( Health::is_healthy() );
		$this->assertSame( 'known-epoch', get_option( Sync_Journal::EPOCH_OPTION ) );
		$this->assertSame( '7', get_option( 'wcpos_change_log_prune_watermark_product' ) );
		$this->assertSame( '9', get_option( 'wcpos_change_log_prune_watermark_order' ) );
		$this->assertSame( $backfill, get_option( Sync_Journal::BACKFILL_OPTION ) );
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION ) );
		foreach ( $this->queries as $query ) {
			if ( preg_match( '/^\s*(CREATE|ALTER|DESCRIBE)\b/i', $query ) ) {
				$this->assertStringNotContainsString( $wpdb->prefix . Health::SYNC_JOURNAL_TABLE, $query );
				$this->assertStringNotContainsString( $wpdb->prefix . Health::STORED_DIGEST_TABLE, $query );
			}
		}
	}

	/** A missing journal starts a new epoch and clears its prune watermark. */
	public function test_repair_journal_only_missing_reinstalls_journal_with_new_epoch(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'digest', 'mutations' ) );
		update_option( Sync_Journal::EPOCH_OPTION, 'known-epoch' );
		( new Sync_Journal() )->advance_prune_watermark( 'product', 7 );
		$this->record_queries();

		// Act.
		$repaired = Store_Repair::repaired();

		// Assert.
		$this->assertTrue( $repaired );
		$this->assertTrue( Health::is_healthy() );
		$this->assertNotSame( 'known-epoch', get_option( Sync_Journal::EPOCH_OPTION ) );
		$this->assertFalse( get_option( 'wcpos_change_log_prune_watermark_product' ) );
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION ) );
		foreach ( $this->queries as $query ) {
			if ( preg_match( '/^\s*(CREATE|ALTER|DESCRIBE)\b/i', $query ) ) {
				$this->assertStringNotContainsString( $wpdb->prefix . Health::STORED_DIGEST_TABLE, $query );
				$this->assertStringNotContainsString( $wpdb->prefix . Health::MUTATIONS_TABLE, $query );
			}
		}
	}

	/** A store with no surviving tables is rebuilt with a new epoch. */
	public function test_repair_all_missing_recreates_all_with_one_new_epoch(): void {
		// Arrange.
		$this->use_alt_site( array() );
		update_option( Sync_Journal::EPOCH_OPTION, 'known-epoch' );
		// Act.
		$repaired = Store_Repair::repaired();
		// Assert.
		$this->assertTrue( $repaired );
		$this->assertTrue( Health::is_healthy() );
		$this->assertNotSame( 'known-epoch', get_option( Sync_Journal::EPOCH_OPTION ) );
	}

	/** An unlatched store remains the normal installer's responsibility. */
	public function test_repair_unlatched_site_does_nothing(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		update_option( Api::SCHEMA_OPTION, '13' );
		$this->record_queries();
		// Act.
		$repaired = Store_Repair::repaired();
		// Assert.
		$this->assertFalse( $repaired );
		$this->assertFalse( Health::table_exists( $wpdb->prefix . Health::STORED_DIGEST_TABLE ) );
		$this->assertFalse( get_transient( Store_Repair::ATTEMPT_TRANSIENT ) );
		$this->assertStringNotContainsString( 'woocommerce_pos_db_upgrade_lock', implode( "\n", $this->queries ) );
		$this->assertSame( 0, preg_match( '/^\s*CREATE/im', implode( "\n", $this->queries ) ) );
	}

	/** Failed CREATEs are logged once and throttled until the marker expires. */
	public function test_repair_failing_create_attempts_once_per_interval(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		$this->fail_create( $wpdb->prefix . Health::STORED_DIGEST_TABLE );
		$this->capture_logs();
		$this->record_queries();
		$suppress = $wpdb->suppress_errors();
		try {
			// Act / Assert: the first attempt fails and logs both diagnostics.
			$this->assertFalse( Store_Repair::repaired() );
			$this->assertFalse( Health::table_exists( $wpdb->prefix . Health::STORED_DIGEST_TABLE ) );
			$this->assertCount( 1, preg_grep( '/Sync table could not be created/', $this->logs ) );
			$this->assertCount( 1, preg_grep( '/^warning .*Sync store tables were missing; repair attempted/', $this->logs ) );
			$this->assertNotFalse( get_transient( Store_Repair::ATTEMPT_TRANSIENT ) );
			$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION ) );
			$this->queries = array();
			$this->logs    = array();
			Logger::reset_dedup_state();

			// Act / Assert: another poll neither installs nor logs.
			$this->assertFalse( Store_Repair::repaired() );
			$this->assertSame( 0, preg_match( '/^\s*CREATE/im', implode( "\n", $this->queries ) ) );
			$this->assertSame( array(), $this->logs );
			delete_transient( Store_Repair::ATTEMPT_TRANSIENT );
			Logger::reset_dedup_state();
			$this->logs = array();

			// Act / Assert: clearing the interval allows one more logged attempt.
			$this->assertFalse( Store_Repair::repaired() );
			$this->assertCount( 1, preg_grep( '/Sync table could not be created/', $this->logs ) );
			$this->assertCount( 1, preg_grep( '/^warning .*Sync store tables were missing; repair attempted/', $this->logs ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	/** A later successful digest repair must not regenerate the journal epoch again. */
	public function test_repair_partial_failure_regenerates_epoch_once(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'mutations' ) );
		update_option( Sync_Journal::EPOCH_OPTION, 'known-epoch' );
		$rewrite  = $this->fail_create( $wpdb->prefix . Health::STORED_DIGEST_TABLE );
		$suppress = $wpdb->suppress_errors();
		try {
			// Act / Assert: only the journal is successfully recreated.
			$this->assertFalse( Store_Repair::repaired() );
			$this->assertTrue( Health::table_exists( $wpdb->prefix . Health::SYNC_JOURNAL_TABLE ) );
			$epoch_1 = get_option( Sync_Journal::EPOCH_OPTION );
			$this->assertNotSame( 'known-epoch', $epoch_1 );
			$this->assertFalse( Health::table_exists( $wpdb->prefix . Health::STORED_DIGEST_TABLE ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		remove_filter( 'query', $rewrite );
		delete_transient( Store_Repair::ATTEMPT_TRANSIENT );
		Logger::reset_dedup_state();
		// Act / Assert: the second attempt keeps the new epoch.
		$this->assertTrue( Store_Repair::repaired() );
		$this->assertTrue( Health::is_healthy() );
		$this->assertSame( $epoch_1, get_option( Sync_Journal::EPOCH_OPTION ) );
	}

	/** A held upgrade lock prevents an attempt without consuming the interval. */
	public function test_repair_lock_held_does_nothing(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		update_option( 'woocommerce_pos_db_upgrade_lock.lock', time(), false );
		// Act.
		$repaired = Store_Repair::repaired();
		// Assert.
		$this->assertFalse( $repaired );
		$this->assertFalse( Health::table_exists( $wpdb->prefix . Health::STORED_DIGEST_TABLE ) );
		$this->assertFalse( get_transient( Store_Repair::ATTEMPT_TRANSIENT ) );
		$this->assertNotFalse( get_option( 'woocommerce_pos_db_upgrade_lock.lock' ) );
	}

	/** The request that repairs a missing digest is served immediately. */
	public function test_gate_serves_request_after_digest_repair(): void {
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/changes/tick' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( Health::is_healthy() );
	}

	/** A failed repair keeps the existing sync-unavailable REST response. */
	public function test_gate_still_503_when_create_keeps_failing(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		$this->fail_create( $wpdb->prefix . Health::STORED_DIGEST_TABLE );
		$suppress = $wpdb->suppress_errors();
		try {
			// Act.
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/changes/tick' ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		// Assert.
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wcpos_sync_unavailable', $response->get_data()['code'] );
	}

	/** A healthy site's gate never reads repair state or takes its lock. */
	public function test_gate_healthy_site_never_reaches_repair(): void {
		// Arrange.
		$this->record_queries();
		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/changes/tick' ) );
		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'woocommerce_pos_db_upgrade_lock', implode( "\n", $this->queries ) );
		$this->assertStringNotContainsString( 'wcpos_sync_store_repair_attempt', implode( "\n", $this->queries ) );
		$this->assertFalse( get_transient( Store_Repair::ATTEMPT_TRANSIENT ) );
	}

	/** A crash releases the lock but leaves the interval marker in place. */
	public function test_repair_crash_mid_install_still_counts_as_an_attempt(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		$table = $wpdb->prefix . Health::STORED_DIGEST_TABLE;
		$crash = static function ( $query ) use ( $table ) {
			if ( preg_match( '/^\s*CREATE\s+(TEMPORARY\s+)?TABLE/i', $query ) && false !== strpos( $query, $table ) ) {
				throw new \RuntimeException( 'simulated crash' );
			}
			return $query;
		};
		add_filter( 'query', $crash );
		$this->filters[] = array( 'query', $crash );
		$caught          = false;
		$suppress        = $wpdb->suppress_errors();
		try {
			// Act.
			Store_Repair::repaired();
		} catch ( \RuntimeException $e ) {
			$caught = true;
			$this->assertSame( 'simulated crash', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		// Assert.
		$this->assertTrue( $caught );
		$this->assertNotFalse( get_transient( Store_Repair::ATTEMPT_TRANSIENT ) );
		$this->assertFalse( get_option( 'woocommerce_pos_db_upgrade_lock.lock' ) );
		remove_filter( 'query', $crash );
		$this->record_queries();
		Logger::reset_dedup_state();
		$this->assertFalse( Store_Repair::repaired() );
		$this->assertSame( 0, preg_match( '/^\s*CREATE/im', implode( "\n", $this->queries ) ) );
	}

	/** The under-lock re-read sees a marker hidden by a cached option miss. */
	public function test_repair_marker_set_by_another_request_is_seen_under_the_lock(): void {
		global $wpdb;
		// Arrange.
		$this->use_alt_site( array( 'journal', 'mutations' ) );
		get_transient( Store_Repair::ATTEMPT_TRANSIENT );
		$rows = array(
			'_transient_timeout_' => time() + 900,
			'_transient_'         => time(),
		);
		foreach ( $rows as $prefix => $value ) {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $prefix . Store_Repair::ATTEMPT_TRANSIENT,
					'option_value' => $value,
					'autoload'     => 'no',
				)
			);
		}
		$this->record_queries();
		// Act.
		$repaired = Store_Repair::repaired();
		// Assert.
		$this->assertFalse( $repaired );
		$this->assertSame( 0, preg_match( '/^\s*CREATE/im', implode( "\n", $this->queries ) ) );
		$this->assertFalse( Health::table_exists( $wpdb->prefix . Health::STORED_DIGEST_TABLE ) );
	}
}
