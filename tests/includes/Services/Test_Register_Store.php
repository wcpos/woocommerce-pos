<?php
/**
 * Register store tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Option_Register_Repository;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WP_UnitTestCase;

/** Free's one register in the wcpos_register option, and the repository seam. */
class Test_Register_Store extends WP_UnitTestCase {
	use Closure_Test_Fixture;

	/** A fresh site: no register yet. Rolled back with the test unless a closure commits it. */
	public function setUp(): void {
		parent::setUp();
		delete_option( Option_Register_Repository::OPTION );
	}

	/** The first read mints one register under the default lock; later reads return it. */
	public function test_get_on_fresh_site_mints_one_default_register_under_the_lock(): void {
		// Arrange.
		$queries = array();
		$trace = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $trace );

		// Act.
		$rows = ( new Register_Store() )->list( array() );
		remove_filter( 'query', $trace );

		// Assert.
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Register', $rows[0]['name'] );
		$this->assertNull( $rows[0]['store_id'] );
		$this->assertNull( $rows[0]['default_float'] );
		$this->assertNull( $rows[0]['counters_started_at_gmt'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rows[0]['created_at_gmt'] );
		$this->assertArrayNotHasKey( 'status', $rows[0] );
		$this->assertArrayNotHasKey( 'platform', $rows[0] );
		$this->assertArrayNotHasKey( 'last_seen_at_gmt', $rows[0] );
		$option = get_option( Option_Register_Repository::OPTION );
		$this->assertSame( $rows[0]['id'], $option['register']['id'] );
		$position = static function ( string $needle, string $also = '' ) use ( $queries ) {
			foreach ( $queries as $index => $sql ) {
				// PHP 7.4 returns false for an empty needle (PHP 8 returns 0), so test it explicitly.
				if ( false !== strpos( $sql, $needle ) && ( '' === $also || false !== strpos( $sql, $also ) ) ) {
					return $index;
				}
			}
			return -1;
		};
		$lock = $position( "GET_LOCK( 'wcpos_register_default'" );
		$write = $position( 'INSERT INTO', "'" . Option_Register_Repository::OPTION . "'" );
		$this->assertGreaterThan( -1, $lock );
		$this->assertGreaterThan( $lock, $write );
		$this->assertGreaterThan( $write, $position( "RELEASE_LOCK( 'wcpos_register_default' )" ) );
		$store = new Register_Store();
		$this->assertSame( $rows[0], $store->get( $rows[0]['id'] ) );
		$this->assertTrue( $store->exists( $rows[0]['id'] ) );
		$this->assertSame( array( $rows[0]['id'] ), array_column( $store->list( array() ), 'id' ) );
	}

	/** A register minted by another request while this one waited for the lock is kept, not doubled. */
	public function test_ensure_default_rereads_inside_the_lock_and_keeps_a_concurrent_mint(): void {
		// Arrange.
		global $wpdb;
		$theirs = wp_generate_uuid4();
		$this->assertFalse( get_option( Option_Register_Repository::OPTION ) );
		$race = static function ( $sql ) use ( $wpdb, $theirs ) {
			if ( false !== strpos( $sql, "GET_LOCK( 'wcpos_register_default'" ) ) {
				$wpdb->insert(
					$wpdb->options,
					array(
						'option_name' => Option_Register_Repository::OPTION,
						'option_value' => maybe_serialize(
							array(
								'register' => array(
									'id' => $theirs,
									'name' => 'Theirs',
									'store_id' => null,
									'default_float' => null,
									'created_at_gmt' => '2026-09-11 08:00:00',
								),
							)
						),
						'autoload' => 'yes',
					)
				);
			}
			return $sql;
		};
		add_filter( 'query', $race );

		// Act.
		try {
			( new Register_Store() )->ensure_default();
		} finally {
			remove_filter( 'query', $race );
		}

		// Assert.
		$this->assertSame( array( $theirs ), array_column( ( new Register_Store() )->list( array() ), 'id' ) );
	}

	/** Free holds one register: a second create is refused and the first is untouched. */
	public function test_create_second_register_is_refused(): void {
		// Arrange.
		$store = new Register_Store();
		$first = $store->list( array() )[0];

		// Act.
		try {
			$store->create( array( 'name' => 'Second' ) );
			$this->fail( 'Expected Free to refuse a second register.' );
		} catch ( \RuntimeException $error ) {
			// Assert.
			$this->assertSame( 'Free holds one register.', $error->getMessage() );
		}
		$this->assertSame( array( $first ), ( new Register_Store() )->list( array() ) );
	}

	/** Free's register has no store and no status: update changes the name and float only. */
	public function test_update_ignores_store_id_and_status(): void {
		// Arrange.
		$store = new Register_Store();
		$id = $store->list( array() )[0]['id'];

		// Act.
		$edited = $store->update(
			$id,
			array(
				'name' => 'Front',
				'default_float' => '20.5000',
				'store_id' => 13,
				'status' => 'retired',
			)
		);

		// Assert.
		$this->assertSame( 'Front', $edited['name'] );
		$this->assertSame( '20.5000', $edited['default_float'] );
		$this->assertNull( $edited['store_id'] );
		$this->assertArrayNotHasKey( 'status', $edited );
		$this->assertSame( $edited, $store->get( $id ) );
		$this->assertNull( $store->update( wp_generate_uuid4(), array( 'name' => 'Missing' ) ) );
	}

	/** History names a register from its row, else its retired name, else "Register". */
	public function test_display_name_reads_row_then_retired_name_then_literal(): void {
		// Arrange.
		$store = new Register_Store();
		$id = $store->update( $store->list( array() )[0]['id'], array( 'name' => 'Front' ) )['id'];
		$retired = wp_generate_uuid4();
		$option = get_option( Option_Register_Repository::OPTION );
		$option['retired'][ $retired ] = array(
			'id' => $retired,
			'name' => 'Old till',
			'store_id' => 7,
			'default_float' => null,
			'created_at_gmt' => '2026-09-01 08:00:00',
		);
		update_option( Option_Register_Repository::OPTION, $option, true );

		// Act / Assert.
		$this->assertSame( 'Front', $store->display_name( $id ) );
		$this->assertSame( 'Old till', $store->display_name( $retired ) );
		$this->assertNull( $store->get( $retired ) );
		$this->assertSame( 'Register', $store->display_name( wp_generate_uuid4() ) );
	}

	/** The derived counters_started_at_gmt is null, then the first numbered session's opening. */
	public function test_counters_started_is_null_then_first_closures_session_opening(): void {
		// Arrange.
		global $wpdb;
		$registers = new Register_Store();
		$id = $registers->list( array() )[0]['id'];
		$this->closure_registers[] = $id;
		$this->assertNull( $registers->get( $id )['counters_started_at_gmt'] );
		$closures = new Closure_Store();

		// Act.
		$closures->create( $this->closure_fields( $this->closure_session( $id ) ) );
		$second = $this->closure_session( $id );
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'opened_at_gmt' => '2026-09-12 08:00:00' ), array( 'id' => $second['id'] ) );
		$closures->create( $this->closure_fields( ( new Register_Session_Store() )->get( $second['id'] ), 2 ) );

		// Assert.
		$this->assertSame( '2026-09-11T08:00:00Z', $registers->get( $id )['counters_started_at_gmt'] );
		$this->assertSame( '2026-09-11T08:00:00Z', $registers->list( array() )[0]['counters_started_at_gmt'] );
		$this->assertSame( 2, $registers->get( $id )['counters']['last_closure_number'] );
	}

	/** A session opened earlier but closed second does not move the start: it is closure 1's session opening. */
	public function test_counters_started_is_closure_ones_session_when_an_earlier_session_closes_later(): void {
		// Arrange: B, opened at 08:00, closes first and takes number 1; A's opening (an offline
		// till's, synced late) is 07:00, earlier than B's, but A closes second.
		global $wpdb;
		$registers = new Register_Store();
		$id = $registers->list( array() )[0]['id'];
		$this->closure_registers[] = $id;
		$sessions = new Register_Session_Store();
		$closures = new Closure_Store();

		// Act.
		$first = $closures->create( $this->closure_fields( $this->closure_session( $id ) ) );
		$a = $this->closure_session( $id );
		$wpdb->update( $sessions->table_name(), array( 'opened_at_gmt' => '2026-09-11 07:00:00' ), array( 'id' => $a['id'] ) );
		$second = $closures->create( $this->closure_fields( $sessions->get( $a['id'] ), 2 ) );

		// Assert.
		$this->assertSame( 1, $first['number'] );
		$this->assertSame( 2, $second['number'] );
		$this->assertSame( '2026-09-11T08:00:00Z', $registers->get( $id )['counters_started_at_gmt'] );
		$this->assertSame( '2026-09-11T08:00:00Z', $registers->list( array() )[0]['counters_started_at_gmt'] );
	}

	/** While schema 12 is pending, reads come from the legacy table, nothing is minted and writes are refused; the lazy mint resumes once it is gone. */
	public function test_pending_register_fold_reads_the_legacy_table_mints_nothing_and_refuses_writes(): void {
		// Arrange.
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$legacy = $wpdb->prefix . Register_Store::LEGACY_TABLE;
		$id = wp_generate_uuid4();
		update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table, fixed DDL.
			$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$legacy} ( id CHAR(36) NOT NULL, name VARCHAR(191) NOT NULL, store_id BIGINT NULL, default_float DECIMAL(19,4) NULL, created_at_gmt DATETIME NOT NULL, PRIMARY KEY  (id) ) {$wpdb->get_charset_collate()}" ) );
			$this->assertSame(
				1,
				$wpdb->insert(
					$legacy,
					array(
						'id' => $id,
						'name' => 'Dev till',
						'store_id' => 3,
						'created_at_gmt' => '2026-09-01 08:00:00',
					)
				)
			);
			$store = new Register_Store();

			// Act / Assert: reads.
			$this->assertSame( array( $id ), array_column( $store->list( array() ), 'id' ) );
			$this->assertSame( 'Dev till', $store->get( $id )['name'] );
			$this->assertTrue( $store->exists( $id ) );
			$this->assertSame( 'Dev till', $store->display_name( $id ) );
			$this->assertFalse( get_option( Option_Register_Repository::OPTION ) );

			// Act / Assert: writes.
			foreach ( array( 'create', 'update' ) as $write ) {
				try {
					'create' === $write ? $store->create( array( 'name' => 'New' ) ) : $store->update( $id, array( 'name' => 'Renamed' ) );
					$this->fail( "Expected {$write} to be refused while migrating." );
				} catch ( \RuntimeException $error ) {
					$this->assertSame( 'Register storage is migrating.', $error->getMessage(), $write );
				}
			}

			// Act / Assert: the table gone, the lazy default mints.
			$wpdb->query( "DROP TABLE {$legacy}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			$rows = ( new Register_Store() )->list( array() );
			$this->assertCount( 1, $rows );
			$this->assertNotSame( $id, $rows[0]['id'] );
			$this->assertSame( $rows[0]['id'], get_option( Option_Register_Repository::OPTION )['register']['id'] );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			delete_option( Option_Register_Repository::OPTION );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, false );
			$wpdb->query( 'COMMIT' );
		}
	}

	/** Many registers (Pro's repository) list by name, filter by store, with one closure query and one current-session query, never one per register. */
	public function test_list_with_bound_repository_filters_and_orders_by_name_in_one_query_per_kind(): void {
		// Arrange.
		$this->use_multi_register_repository();
		$b = $this->create_register(
			array(
				'name' => 'B',
				'store_id' => 987,
			)
		);
		$a = $this->create_register(
			array(
				'name' => 'A',
				'store_id' => 987,
			)
		);
		$this->create_register( array( 'name' => 'Unassigned' ) );
		$store = new Register_Store();
		$closure_queries = array();
		$trace = static function ( $sql ) use ( &$closure_queries ) {
			if ( false !== strpos( $sql, ( new Register_Session_Store() )->table_name() ) && false !== stripos( $sql, 'SELECT' ) ) {
				$closure_queries[] = $sql;
			}
			return $sql;
		};

		// Act.
		add_filter( 'query', $trace );
		$listed = $store->list( array( 'store_id' => 987 ) );
		remove_filter( 'query', $trace );

		// Assert.
		$this->assertSame( array( $a['id'], $b['id'] ), array_column( $listed, 'id' ) );
		$this->assertSame( 987, $listed[0]['store_id'] );
		// The latest closures and the current sessions both live in the session table: one SELECT each.
		$this->assertCount( 2, $closure_queries );
		$this->assertSame( array( 'Unassigned' ), array_column( $store->list( array( 'store_id' => array( null ) ) ), 'name' ) );
		$this->assertSame( array(), $store->list( array( 'store_id' => 988 ) ) );
		$this->assertFalse( get_option( Option_Register_Repository::OPTION ) );
	}

	/** The named register lock excludes a different database connection. */
	public function test_register_lock_excludes_another_connection(): void {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$id = wp_generate_uuid4();
		$store = new Register_Store();
		try {
			$this->assertTrue( $store->lock( strtoupper( $id ) ) );
			$this->assertSame( '0', (string) $other->get_var( $other->prepare( 'SELECT IS_FREE_LOCK( %s )', 'wcpos_register_' . $id ) ) );
			$store->unlock( $id );
			$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT IS_FREE_LOCK( %s )', 'wcpos_register_' . $id ) ) );
		} finally {
			$store->unlock( $id );
			$other->close();
		}
	}
}
