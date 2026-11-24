<?php
/**
 * Credential-attempt row sweep and live-row ceiling tests.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use ReflectionClassConstant;
use WCPOS\WooCommercePOS\Services\Credential_Lockout;
use WP_UnitTestCase;

/**
 * Expired rows are swept by every attempt, and live rows have a ceiling real accounts are exempt from.
 */
class Credential_Lockout_Rows_Test extends WP_UnitTestCase {
	/** Connecting address used by every attempt. */
	private const PEER = '203.0.113.40';

	/**
	 * Original REMOTE_ADDR, or null when it was unset.
	 *
	 * @var string|null
	 */
	private $remote_addr;

	/** Set the connecting address. */
	public function setUp(): void {
		parent::setUp();
		$this->remote_addr      = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test-owned request fixture.
		$_SERVER['REMOTE_ADDR'] = self::PEER;
	}

	/** Restore the connecting address. */
	public function tearDown(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
		parent::tearDown();
	}

	/**
	 * A Credential_Lockout constant.
	 *
	 * @param string $name The constant name.
	 */
	public static function constant( string $name ): int {
		return ( new ReflectionClassConstant( Credential_Lockout::class, $name ) )->getValue();
	}

	/**
	 * Insert counter rows in one multi-row statement.
	 *
	 * @param int    $count  How many rows.
	 * @param int    $expiry The rows' expires-at.
	 * @param string $tag    Distinguishes this batch's names.
	 * @param string $prefix Before each md5: 'n' for unknown-name rows, '' for peer and account rows.
	 */
	public static function insert_rows( int $count, int $expiry, string $tag, string $prefix = '' ): void {
		global $wpdb;
		$values = array();
		$args   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$values[] = "(%s, %s, 'no')";
			array_push( $args, 'wcpos_auth_attempts_' . $prefix . md5( $tag . '-' . $i ), '1|' . $expiry . '|' . $tag );
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ', ', $values ), $args ) ); // phpcs:ignore WordPress.DB -- Test fixture rows.
	}

	/**
	 * Counter rows whose window is open (live) or ended.
	 *
	 * @param bool   $live   Count live rows, or expired ones.
	 * @param string $prefix Only rows whose name continues with this ('n' for unknown-name rows).
	 */
	public static function count_rows( bool $live, string $prefix = '' ): int {
		global $wpdb;
		$expiry = "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, '|', 2), '|', -1) AS UNSIGNED)";
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND {$expiry} " . ( $live ? '>=' : '<' ) . ' %d', $wpdb->esc_like( 'wcpos_auth_attempts_' . $prefix ) . '%', time() ) ); // phpcs:ignore WordPress.DB -- Test fixture rows.
	}

	/**
	 * Add live unknown-name rows until the current site holds $total of them, which fills the ceiling.
	 *
	 * @param int $total Live unknown-name rows wanted.
	 */
	public static function fill_live( int $total ): void {
		self::insert_rows( $total - self::count_rows( true, 'n' ), time() + 900, 'live-fill-' . wp_rand(), 'n' );
	}

	/**
	 * A counter row's stored value.
	 *
	 * @param string $bucket The bucket key.
	 */
	private function row( string $bucket ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wcpos_auth_attempts_' . $bucket ) );
	}

	/**
	 * End a counter row's window, keeping everything after the expiry.
	 *
	 * @param string $bucket The bucket key.
	 */
	private function end_window( string $bucket ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', %d, SUBSTRING(option_value, LENGTH(SUBSTRING_INDEX(option_value, '|', 2)) + 1)) WHERE option_name = %s", time() - 1, 'wcpos_auth_attempts_' . $bucket ) );
	}

	/**
	 * Expired rows go away one batch per attempt, with no sign-in needed.
	 */
	public function test_rows_expired_rows_are_swept_by_an_attempt_without_any_sign_in(): void {
		// Arrange.
		self::insert_rows( self::constant( 'SWEEP_BATCH' ) + 50, time() - 60, 'expired' );
		self::insert_rows( 1, time() + 900, 'foreign' );
		$foreign = $this->row( md5( 'foreign-0' ) );

		// Act.
		$first = Credential_Lockout::reserve_bucket_attempt( md5( 'sweep-a' ), 10, 900 );

		// Assert.
		$this->assertNotNull( $first );
		$this->assertSame( 50, self::count_rows( false ) );
		$this->assertSame( $foreign, $this->row( md5( 'foreign-0' ) ) );
		$this->assertNotNull( $this->row( md5( 'sweep-a' ) ) );

		// Act / Assert: the next attempt sweeps the rest.
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( md5( 'sweep-b' ), 10, 900 ) );
		$this->assertSame( 0, self::count_rows( false ) );
	}

	/**
	 * A successful check deletes no more expired rows than one attempt does.
	 */
	public function test_rows_clear_deletes_at_most_one_batch_of_expired_rows(): void {
		// Arrange.
		self::insert_rows( self::constant( 'SWEEP_BATCH' ) + 50, time() - 60, 'expired' );

		// Act.
		Credential_Lockout::clear( 'no-such-user' );

		// Assert.
		$this->assertSame( 50, self::count_rows( false ) );
	}

	/**
	 * Unknown names get new rows only below the ceiling.
	 */
	public function test_rows_below_the_ceiling_admit_a_new_unknown_name_and_at_the_ceiling_refuse_the_next(): void {
		// Arrange.
		$max = self::constant( 'MAX_LIVE_ROWS' );
		self::fill_live( $max - 1 );

		// Act / Assert.
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'unknown-a' ) );
		$this->assertNotNull( $this->row( 'n' . md5( 'input:unknown-a|' . self::PEER ) ) );

		// Act / Assert.
		$this->assertNull( Credential_Lockout::reserve_attempt( 'unknown-b' ) );
		$this->assertNull( $this->row( 'n' . md5( 'input:unknown-b|' . self::PEER ) ) );
		$this->assertSame( $max, self::count_rows( true ) );
	}

	/**
	 * Peer and real-account rows, which carry no unknown-name prefix, leave the ceiling untouched.
	 */
	public function test_rows_rows_that_are_not_unknown_names_do_not_count_toward_the_ceiling(): void {
		// Arrange.
		self::insert_rows( self::constant( 'MAX_LIVE_ROWS' ), time() + 900, 'known' );

		// Act.
		$admitted = Credential_Lockout::reserve_attempt( 'unknown-f' );

		// Assert.
		$this->assertNotNull( $admitted );
		$this->assertNotNull( $this->row( 'n' . md5( 'input:unknown-f|' . self::PEER ) ) );
	}

	/**
	 * The sweep's DELETE checks the expiry again, so a row revived after the SELECT stays.
	 */
	public function test_rows_sweep_keeps_a_row_revived_between_its_select_and_its_delete(): void {
		// Arrange.
		global $wpdb;
		self::insert_rows( 5, time() - 60, 'expired' );
		$revived = 'wcpos_auth_attempts_' . md5( 'expired-0' );
		$state   = 'waiting';
		$revive  = static function ( $query ) use ( &$state, $revived ) {
			global $wpdb;
			// The sweep's DELETE names the row only when its SELECT returned it as expired.
			if ( 'waiting' === $state && 0 === strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, "'" . $revived . "'" ) ) {
				// Another attempt revives that row just before the DELETE runs.
				$state = 'revived';
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", '1|' . ( time() + 900 ) . '|revived', $revived ) );
			}
			return $query;
		};
		add_filter( 'query', $revive );

		// Act.
		$admitted = Credential_Lockout::reserve_bucket_attempt( md5( 'revive' ), 10, 900 );
		remove_filter( 'query', $revive );

		// Assert.
		$this->assertNotNull( $admitted );
		$this->assertSame( 'revived', $state );
		$this->assertStringEndsWith( '|revived', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $revived ) ) );
		$this->assertSame( 0, self::count_rows( false ) );
	}

	/**
	 * An attempt refused at the ceiling writes nothing, and sweeps nothing either.
	 */
	public function test_rows_a_refused_attempt_sweeps_no_expired_rows(): void {
		// Arrange.
		self::insert_rows( 5, time() - 60, 'expired' );
		self::fill_live( self::constant( 'MAX_LIVE_ROWS' ) );

		// Act.
		$refused = Credential_Lockout::reserve_attempt( 'unknown-g' );

		// Assert.
		$this->assertNull( $refused );
		$this->assertSame( 5, self::count_rows( false ) );
	}

	/**
	 * An attempt refused because its bucket is full writes nothing, and sweeps nothing either.
	 */
	public function test_rows_a_full_bucket_refusal_sweeps_no_expired_rows(): void {
		// Arrange: fill the bucket first, since the admitted attempts sweep.
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( 'F', 2, 900 ) );
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( 'F', 2, 900 ) );
		self::insert_rows( 5, time() - 60, 'expired' );

		// Act.
		$refused = Credential_Lockout::reserve_bucket_attempt( 'F', 2, 900 );

		// Assert.
		$this->assertNull( $refused );
		$this->assertSame( 5, self::count_rows( false ) );
	}

	/**
	 * One sweep reads at most SWEEP_SCAN rows, however many counter rows there are.
	 */
	public function test_rows_a_sweep_reads_at_most_sweep_scan_rows(): void {
		// Arrange: more live rows than one sweep reads, and a bucket whose next attempt writes.
		global $wpdb;
		$scan = self::constant( 'SWEEP_SCAN' );
		self::insert_rows( 1200, time() + 900, 'scan' );
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( md5( 'scan-bucket' ), 10, 900 ) );
		$sweeps  = array();
		$pending = false;
		$watch   = static function ( $query ) use ( &$sweeps, &$pending ) {
			global $wpdb;
			// The filter runs before the next query, so num_rows still holds the sweep SELECT's rows.
			if ( $pending ) {
				$sweeps[ \count( $sweeps ) - 1 ][1] = $wpdb->num_rows;
				$pending                            = false;
			}
			if ( 0 === strpos( $query, 'SELECT option_name' ) && false !== strpos( str_replace( '\\', '', $query ), 'wcpos_auth_attempts_' ) ) {
				$sweeps[] = array( $query, 0 );
				$pending  = true;
			}
			return $query;
		};
		add_filter( 'query', $watch );

		// Act.
		$admitted = Credential_Lockout::reserve_bucket_attempt( md5( 'scan-bucket' ), 10, 900 );
		remove_filter( 'query', $watch );
		if ( $pending ) {
			$sweeps[ \count( $sweeps ) - 1 ][1] = $wpdb->num_rows;
		}

		// Assert: each statement is limited to what the sweep has left to read, and together they
		// read exactly SWEEP_SCAN of the 1201 rows.
		$this->assertNotNull( $admitted );
		$read = 0;
		foreach ( $sweeps as $sweep ) {
			$this->assertSame( 1, preg_match( '/ LIMIT (\d+)$/', trim( $sweep[0] ), $match ) );
			$this->assertSame( $scan - $read, (int) $match[1] );
			$read += $sweep[1];
		}
		$this->assertSame( $scan, $read );
	}

	/**
	 * Unknown-name rows, which sort after every hex name, are swept as well.
	 */
	public function test_rows_expired_unknown_name_rows_are_swept_too(): void {
		// Arrange.
		self::insert_rows( self::constant( 'SWEEP_BATCH' ) + 50, time() - 60, 'expired', 'n' );

		// Act / Assert.
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( md5( 'sweep-n-a' ), 10, 900 ) );
		$this->assertSame( 50, self::count_rows( false, 'n' ) );
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( md5( 'sweep-n-b' ), 10, 900 ) );
		$this->assertSame( 0, self::count_rows( false, 'n' ) );
	}

	/**
	 * An unknown name at the limit leaves no lock transient behind, and its full row still refuses it.
	 */
	public function test_rows_an_unknown_name_at_the_limit_gets_no_lock_transient_and_is_still_refused(): void {
		// Arrange.
		$bucket = 'n' . md5( 'input:unknown-h|' . self::PEER );

		// Act: five counted failures.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertNotNull( Credential_Lockout::reserve_attempt( 'unknown-h' ) );
			Credential_Lockout::record_failure( 'unknown-h' );
		}

		// Assert.
		$this->assertFalse( get_transient( 'wcpos_auth_lock_' . $bucket ) );
		$this->assertNull( Credential_Lockout::reserve_attempt( 'unknown-h' ) );

		// Act / Assert: once the row's window ends, the name is admitted again.
		$this->end_window( $bucket );
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'unknown-h' ) );
	}

	/**
	 * At the ceiling a new peer bucket is admitted, and a bucket already live keeps counting.
	 */
	public function test_rows_at_the_ceiling_admit_a_new_peer_bucket_and_keep_counting_an_existing_one(): void {
		// Arrange.
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( 'P', 10, 900 ) );
		self::fill_live( self::constant( 'MAX_LIVE_ROWS' ) );

		// Act / Assert.
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( 'new-bucket', 10, 900 ) );
		$this->assertStringStartsWith( '1|', $this->row( 'new-bucket' ) );

		// Act / Assert.
		$this->assertStringStartsWith( '1|', $this->row( 'P' ) );
		$this->assertNotNull( Credential_Lockout::reserve_bucket_attempt( 'P', 10, 900 ) );
		$this->assertStringStartsWith( '2|', $this->row( 'P' ) );
	}

	/**
	 * A real account keeps its lockout at the ceiling, and still unlocks.
	 */
	public function test_rows_at_the_ceiling_a_real_account_still_locks_after_five_failures_and_unlocks(): void {
		// Arrange.
		$user   = self::factory()->user->create_and_get( array( 'user_login' => 'ceiling-manager' ) );
		$bucket = md5( 'user:' . $user->ID . '|' . self::PEER );
		self::fill_live( self::constant( 'MAX_LIVE_ROWS' ) );

		// Act / Assert: five failures are counted, the sixth attempt is locked.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertNotNull( Credential_Lockout::reserve_attempt( 'ceiling-manager' ) );
			Credential_Lockout::record_failure( 'ceiling-manager' );
		}
		$this->assertNotFalse( get_transient( 'wcpos_auth_lock_' . $bucket ) );
		$this->assertNull( Credential_Lockout::reserve_attempt( 'ceiling-manager' ) );

		// Act / Assert: a successful check unlocks.
		Credential_Lockout::clear( 'ceiling-manager' );
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'ceiling-manager' ) );

		// Act / Assert: an ended window and an expired lock unlock too.
		Credential_Lockout::record_failure( 'ceiling-manager' );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertNotNull( Credential_Lockout::reserve_attempt( 'ceiling-manager' ) );
			Credential_Lockout::record_failure( 'ceiling-manager' );
		}
		$this->assertNull( Credential_Lockout::reserve_attempt( 'ceiling-manager' ) );
		$this->end_window( $bucket );
		delete_transient( 'wcpos_auth_lock_' . $bucket );
		$this->assertNotNull( Credential_Lockout::reserve_attempt( 'ceiling-manager' ) );
	}

	/**
	 * A live-row count that fails refuses a new row (fail closed).
	 */
	public function test_rows_a_failed_live_row_count_refuses_a_new_unknown_name(): void {
		// Arrange.
		global $wpdb;
		$break = static function ( $query ) {
			return false !== strpos( $query, 'COUNT(*)' ) && false !== strpos( str_replace( '\\', '', $query ), 'wcpos_auth_attempts_' ) ? 'SELECT * FROM wcpos_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors();

		// Act.
		$refused = Credential_Lockout::reserve_attempt( 'unknown-c' );

		// Assert.
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );
		$this->assertNull( $refused );
		$this->assertNull( $this->row( 'n' . md5( 'input:unknown-c|' . self::PEER ) ) );
	}
}
