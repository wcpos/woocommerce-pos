<?php
/**
 * Regression coverage for digest upsert contention (issue #1880).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact regression coverage.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Replace wpdb only for fault injection.

use WCPOS\WooCommercePOS\Logger;
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
	public function test_upsert_contention_retries_only_once( string $type, array $errors, int $calls, ?string $expected_error, string $method ): void {
		// Arrange: create the source row before injecting failures.
		if ( 'customer' === $type ) {
			$id = $this->factory->user->create( array( 'role' => 'customer' ) );
		} elseif ( 'order' === $type ) {
			$id = wc_create_order()->get_id();
		} else {
			$id = $this->factory->post->create(
				array(
					'post_type' => 'product',
					'post_status' => 'publish',
				)
			);
		}
		Integrity_Digest::reset_request_state();
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

		// Act: flush each public recorder through the retry helper and fail-open observer.
		$messages = array();
		$capture  = static function ( $enabled, $message ) use ( &$messages ) {
			$messages[] = $message;
			return false;
		};
		Logger::reset_dedup_state();
		add_filter( 'woocommerce_pos_logging', $capture, 10, 2 );
		try {
			$digest->$method( $id );
			Integrity_Digest::flush_pending_digests();
		} finally {
			remove_filter( 'woocommerce_pos_logging', $capture, 10 );
			Logger::reset_dedup_state();
		}

		// Assert: count only the upsert, excluding session setup and verification queries.
		$prefix = 'product' === $type ? 'upsert stored digest failed: ' : "upsert stored {$type} digest failed: ";
		$this->assertSame( $calls, $GLOBALS['wpdb']->upsert_calls );
		$expected_messages = null === $expected_error ? array() : array( 'Sync digest observer failed (sync will self-heal via scan/rebuild): ' . $prefix . $expected_error );
		$this->assertSame( $expected_messages, $messages );
		if ( null === $expected_error ) {
			$this->assertSame( '1', $this->original_wpdb->get_var( $this->original_wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = %s AND object_id = %d", $type, $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		}
	}

	public function test_rebuild_retries_each_statement_once_on_record_changed(): void {
		// Arrange: every prune and INSERT...SELECT fails once with MariaDB 1020.
		$id     = $this->factory->user->create( array( 'role' => 'customer' ) );
		$digest = new Integrity_Digest();
		$table  = $digest->table_name();
		$this->original_wpdb->delete( $table, array( 'object_type' => 'customer' ) );
		$GLOBALS['wpdb'] = new class( $this->original_wpdb, $table ) extends \wpdb {
			public $attempts = array();
			private $original;
			private $digest_table;

			public function __construct( \wpdb $original, string $table ) {
				foreach ( array( 'prefix', 'posts', 'postmeta', 'users', 'usermeta', 'term_relationships', 'term_taxonomy', 'terms', 'options' ) as $key ) {
					$this->$key = $original->$key;
				}
				$this->original     = $original;
				$this->digest_table = $table;
			}

			public function query( $query ) {
				if ( 0 === strpos( $query, 'DELETE FROM ' . $this->digest_table . ' ' ) || 0 === strpos( $query, 'INSERT INTO ' . $this->digest_table . ' ' ) ) {
					$this->attempts[ $query ] = ( $this->attempts[ $query ] ?? 0 ) + 1;
					if ( 1 === $this->attempts[ $query ] ) {
						$this->last_error = "Record has changed since last read in table 'wp_wcpos_sync_stored_digest'";
						return false;
					}
				}
				$r = $this->original->query( $query );
				$this->last_error = $this->original->last_error;
				return $r;
			}

			public function get_var( $query = null, $x = 0, $y = 0 ) {
				return $this->original->get_var( $query, $x, $y );
			}

			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				return $this->original->get_row( $query, $output, $y );
			}

			public function get_results( $query = null, $output = OBJECT ) {
				return $this->original->get_results( $query, $output );
			}

			public function insert( $table, $data, $format = null ) {
				return $this->original->insert( $table, $data, $format );
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				return $this->original->update( $table, $data, $where, $format, $where_format );
			}

			public function prepare( $query, ...$args ) {
				return $this->original->prepare( $query, ...$args );
			}

			public function _escape( $data ) {
				return $this->original->_escape( $data );
			}
		};

		// Act.
		$result = $digest->rebuild();

		// Assert: six statements, each tried twice, and the customer digest landed.
		$this->assertSame( array( 2, 2, 2, 2, 2, 2 ), array_values( $GLOBALS['wpdb']->attempts ) );
		$this->assertGreaterThan( 0, $result['stored_total'] );
		$this->assertSame( '1', $this->original_wpdb->get_var( $this->original_wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'customer' AND object_id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
	}

	public static function upsert_failures(): array {
		$changed = "Record has changed since last read in table 'wp_wcpos_sync_stored_digest'; try restarting transaction";
		$cases   = array();
		foreach ( array( 'product' => 'record_post_saved', 'customer' => 'record_customer_saved', 'order' => 'record_order_saved' ) as $type => $method ) {
			foreach ( array( $changed, 'Deadlock found', 'Lock wait timeout' ) as $error ) {
				$cases[ $method . ': ' . $error ] = array( $type, array( $error ), 2, null, $method );
			}
			$cases[ $method . ': permanent error' ] = array( $type, array( 'Unknown column' ), 1, 'Unknown column', $method );
			$cases[ $method . ': permanent retry error' ] = array( $type, array( $changed, 'Unknown column' ), 2, 'Unknown column', $method );
			$cases[ $method . ': contention retry error' ] = array( $type, array( $changed, 'Deadlock found' ), 2, 'Deadlock found', $method );
		}
		return $cases;
	}
}
