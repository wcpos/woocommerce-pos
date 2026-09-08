<?php
/**
 * Regression coverage for digest upsert contention (issue #1880).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact regression coverage.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Replace wpdb only for fault injection.

use RuntimeException;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

class Test_Integrity_Digest_Upsert_Retry extends Sync_Store_Test_Case {

	private $original_wpdb;

	public function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'];
		parent::setUp();
	}

	public function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		Integrity_Digest::reset_request_state();
		parent::tearDown();
	}

	/** @dataProvider upsert_failures */
	public function test_upsert_contention_retries_only_once( string $type, array $errors, int $calls, ?string $expected_error ): void {
		// Arrange: create the source row before injecting failures.
		$id = 'product' === $type
			? $this->factory->post->create(
				array(
					'post_type' => 'product',
					'post_status' => 'publish',
				)
			)
			: $this->factory->user->create( array( 'role' => 'customer' ) );
		$digest = new Integrity_Digest();
		$table  = $digest->table_name();
		$GLOBALS['wpdb'] = new class( $this->original_wpdb, $table, $errors ) extends \wpdb {
			public $upsert_calls = 0;
			private $original;
			private $digest_table;
			private $errors;

			public function __construct( \wpdb $original, string $table, array $errors ) {
				// Copy table names only; the original owns the connection and query results.
				foreach ( array( 'prefix', 'posts', 'postmeta', 'users', 'usermeta', 'term_relationships', 'term_taxonomy', 'terms', 'options' ) as $key ) {
					$this->$key = $original->$key;
				}
				$this->original     = $original;
				$this->digest_table = $table;
				$this->errors       = $errors;
			}

			public function query( $query ) {
				if ( 0 === strpos( $query, 'INSERT INTO ' . $this->digest_table . ' ' ) ) {
					++$this->upsert_calls;
					if ( $this->errors ) {
						$this->last_error = array_shift( $this->errors );
						return false;
					}
				}
				$r = $this->original->query( $query );
				$this->last_error = $this->original->last_error;
				return $r;
			}

			public function prepare( $query, ...$args ) {
				return $this->original->prepare( $query, ...$args );
			}

			public function _escape( $data ) {
				return $this->original->_escape( $data );
			}

			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				return $this->original->get_row( $query, $output, $y );
			}

			public function get_results( $query = null, $output = OBJECT ) {
				return $this->original->get_results( $query, $output );
			}
		};

		// Act: exercise each public upsert, not the private retry helper.
		$error = null;
		try {
			if ( 'product' === $type ) {
				$digest->upsert_digest( $id );
			} else {
				$digest->upsert_customer_digest( $id );
			}
		} catch ( RuntimeException $exception ) {
			$error = $exception->getMessage();
		}

		// Assert: count only the upsert, excluding session setup and verification queries.
		$prefix = 'product' === $type ? 'upsert stored digest failed: ' : 'upsert stored customer digest failed: ';
		$this->assertSame( $calls, $GLOBALS['wpdb']->upsert_calls );
		$this->assertSame( null === $expected_error ? null : $prefix . $expected_error, $error );
		if ( null === $expected_error ) {
			$this->assertSame( '1', $this->original_wpdb->get_var( $this->original_wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = %s AND object_id = %d", $type, $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		}
	}

	public static function upsert_failures(): array {
		$changed = "Record has changed since last read in table 'wp_wcpos_sync_stored_digest'; try restarting transaction";
		$cases   = array();
		foreach ( array( 'product', 'customer' ) as $type ) {
			foreach ( array( $changed, 'Deadlock found', 'Lock wait timeout' ) as $error ) {
				$cases[] = array( $type, array( $error ), 2, null );
			}
			$cases[] = array( $type, array( 'Unknown column' ), 1, 'Unknown column' );
			$cases[] = array( $type, array( $changed, 'Unknown column' ), 2, 'Unknown column' );
			$cases[] = array( $type, array( $changed, 'Deadlock found' ), 2, 'Deadlock found' );
		}
		return $cases;
	}
}
