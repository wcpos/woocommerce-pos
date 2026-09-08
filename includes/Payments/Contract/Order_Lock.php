<?php
/**
 * Per-order payment mutation locking.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WCPOS\WooCommercePOS\Logger;
use WP_Error;

/** MySQL mutual exclusion, with a best-effort 300-second option lease fallback. */
final class Order_Lock {
	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;
	/**
	 * Prevent MySQL connection-level reentry by another object.
	 *
	 * @var array<string, bool>
	 */
	private static $owners = array();
	/**
	 * This object's held locks.
	 *
	 * @var array<string, array{driver:string,value:string,count:int}>
	 */
	private $held = array();
	/**
	 * Whether the server keeps more than one named lock per connection.
	 *
	 * @var bool|null
	 */
	private static $multi_lock = null;

	/** Get the shared, request-reentrant lock. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Acquire a lock, waiting five seconds for MySQL contention.
	 *
	 * @param int $order_id Order ID.
	 */
	public function acquire( int $order_id ): bool {
		global $wpdb;
		$name = $this->name( $order_id );
		if ( isset( $this->held[ $name ] ) ) {
			++$this->held[ $name ]['count'];
			return true;
		}
		if ( isset( self::$owners[ $name ] ) ) {
			return false;
		}
		// MySQL before 5.7.5 and MariaDB before 10.0.2 RELEASE the lock a connection holds
		// when it takes a second one, so a nested lock (the settlement parking lock inside
		// record()) would silently drop the order lock. There, a nested lock takes the
		// option lease instead.
		$nested = ! empty( self::$owners ) && ! self::supports_multiple_locks();
		try {
			$result = $nested ? null : $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Advisory locks cannot use the object cache.
		} catch ( \Throwable $exception ) {
			$result = null;
		}
		$value = '';
		if ( null === $result ) {
			$option = 'wcpos_payment_lock_' . absint( $order_id );
			$value  = wp_generate_password( 12, false ) . '|' . time();
			if ( ! $this->add_lease( $option, $value ) ) {
				$existing = (string) get_option( $option, '' );
				$parts    = explode( '|', $existing, 2 );
				$created  = (int) ( $parts[1] ?? $parts[0] );
				if ( $created <= 0 || $created >= time() - 300 || ! $this->delete_owner( $option, $existing ) || ! $this->add_lease( $option, $value ) ) {
					return false;
				}
				Logger::log( sprintf( 'WCPOS payment option lock stale lease taken over on order #%d (age %d seconds).', $order_id, time() - $created ) );
			}
		} elseif ( '1' !== (string) $result ) {
			return false;
		}
		$this->held[ $name ] = array(
			'driver' => null === $result ? 'option' : 'mysql',
			'value' => $value,
			'count' => 1,
		);
		self::$owners[ $name ] = true;
		return true;
	}

	/**
	 * Release only the lock owned by this object.
	 *
	 * @param int $order_id Order ID.
	 */
	public function release( int $order_id ): void {
		global $wpdb;
		$name = $this->name( $order_id );
		if ( ! isset( $this->held[ $name ] ) || --$this->held[ $name ]['count'] > 0 ) {
			return;
		}
		$lock = $this->held[ $name ];
		unset( $this->held[ $name ], self::$owners[ $name ] );
		if ( 'option' === $lock['driver'] ) {
			$this->delete_owner( 'wcpos_payment_lock_' . absint( $order_id ), $lock['value'] );
			return;
		}
		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Advisory locks cannot use the object cache.
		} catch ( \Throwable $exception ) {
			Logger::log( sprintf( 'Could not release WCPOS payment lock on order #%d.', $order_id ) );
		}
	}

	/**
	 * Run a callback under the lock; acquisition failure is a retryable REST error.
	 *
	 * @param int      $order_id Order ID.
	 * @param callable $callback Locked operation.
	 * @return mixed|WP_Error
	 */
	public function with_lock( int $order_id, callable $callback ) {
		if ( ! $this->acquire( $order_id ) ) {
			return new WP_Error(
				'wcpos_payment_locked',
				__( 'Another payment operation is running on this order.', 'woocommerce-pos' ),
				array(
					'status' => 409,
					'retry_after' => 1,
				)
			);
		}
		try {
			return $callback();
		} finally {
			$this->release( $order_id );
		}
	}

	/**
	 * Add a non-autoloaded lease, atomically.
	 *
	 * `add_option()` cannot be used here: it writes an upsert
	 * (`INSERT … ON DUPLICATE KEY UPDATE`), so two tills racing on one order would both
	 * be told they succeeded and the second would silently steal the first's lease.
	 * `INSERT IGNORE` is the atomic form a lock needs — the loser gets 0 rows.
	 *
	 * @param string $name  Option name.
	 * @param string $value Owner token and timestamp.
	 * @phpstan-impure
	 */
	private function add_lease( string $name, string $value ): bool {
		global $wpdb;
		// 'no' is the non-autoload value WordPress has always written and still reads.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic lease insert; add_option() is an upsert and cannot lock. Cache invalidated below.
		if ( ! $inserted ) {
			return false;
		}
		// A prior get_option() miss caches the name in `notoptions`, which would hide the
		// lease we just wrote from the stale-takeover read.
		wp_cache_delete( $name, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		return true;
	}

	/**
	 * Delete a lease only if its owner has not changed (including stale takeover).
	 *
	 * @param string $name  Option name.
	 * @param string $value Observed owner value.
	 */
	private function delete_owner( string $name, string $value ): bool {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic owner-checked lease deletion; cache invalidated below.
		if ( $deleted ) {
			wp_cache_delete( $name, 'options' );
		}
		return (bool) $deleted;
	}

	/**
	 * Whether this server can hold two named locks on one connection (MySQL 5.7.5+,
	 * MariaDB 10.0.2+). Read once per request; `db_server_info()` reports MariaDB as
	 * "5.5.5-10.6.12-MariaDB" and MySQL as "8.0.36".
	 */
	private static function supports_multiple_locks(): bool {
		if ( null === self::$multi_lock ) {
			global $wpdb;
			$info = is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
			if ( preg_match( '/(\d+\.\d+\.\d+)-MariaDB/i', $info, $m ) ) {
				$supported = version_compare( $m[1], '10.0.2', '>=' );
			} elseif ( preg_match( '/^(\d+\.\d+\.\d+)/', $info, $m ) ) {
				$supported = version_compare( $m[1], '5.7.5', '>=' );
			} else {
				$supported = false;
			}
			/**
			 * Filters whether nested named locks are trusted on this database server.
			 *
			 * @since 1.11.0
			 *
			 * @param bool   $supported Detected from the server version.
			 * @param string $info      The server version string.
			 */
			self::$multi_lock = (bool) apply_filters( 'wcpos_order_lock_supports_multiple_locks', $supported, $info ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public payments contract filter.
		}
		return self::$multi_lock;
	}

	/**
	 * Include the blog in request ownership as well as the MySQL name.
	 *
	 * @param int $order_id Order ID.
	 */
	private function name( int $order_id ): string {
		return sprintf( 'wcpos_order_%d_%d', absint( $order_id ), get_current_blog_id() );
	}
}
