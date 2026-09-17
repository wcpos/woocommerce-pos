<?php
/**
 * Request-boundary write coalescing.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Coalesce repeated saves into one write per record between flushes.
 *
 * WooCommerce saves records repeatedly within one request. Keep the last
 * non-null payload per blog/type/id until the owner needs the settled write.
 * A new record beyond capacity flushes first, bounding bulk-import queues;
 * capacity one also preserves the journal's ordering across different orders.
 * Entries retain their blog so multisite switches cannot redirect writes.
 *
 * Owners flush last on shutdown: WooCommerce saves the customer at priority
 * 10 and the session at 20 (which can fire woocommerce_update_customer).
 * The shutdown latch makes any later save write immediately, not wait for a
 * flush that will never come. Draining before writes prevents replay after an
 * exception and lets reentrant saves start a fresh queue.
 */
final class Request_Write_Queue {
	/**
	 * Maximum pending distinct records.
	 *
	 * @var int
	 */
	private int $capacity;

	/**
	 * Writer receiving type, id and payload under the entry's blog.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Pending [blog, type, id, payload] entries.
	 *
	 * @var array<string, array>
	 */
	private array $entries = array();

	/**
	 * Whether the final request-boundary flush has begun.
	 *
	 * @var bool
	 */
	private bool $shutdown_flushed = false;

	/**
	 * Bind a writer and its pending-record capacity.
	 *
	 * @param int      $capacity Maximum pending distinct records.
	 * @param callable $write    Writer receiving type, id and payload.
	 */
	public function __construct( int $capacity, callable $write ) {
		$this->capacity = $capacity;
		$this->write    = $write;
	}

	/**
	 * Owe one write, or write immediately after shutdown.
	 *
	 * @param string $type    Record type.
	 * @param int    $id      Record id.
	 * @param mixed  $payload Optional write context; null retains existing context.
	 */
	public function owe( string $type, int $id, $payload = null ): void {
		if ( $this->shutdown_flushed ) {
			( $this->write )( $type, $id, $payload );
			return;
		}
		$key = $this->key( $type, $id );
		// A capacity flush runs writers that may re-enter owe(), so the pending
		// state is re-read after each flush: the key may now be pending (keep it,
		// merge the payload) or the queue full again. One re-check is enough for
		// any real writer; a pathological one that refills the queue on every
		// flush is then allowed a bounded overflow rather than an endless loop.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( isset( $this->entries[ $key ] ) ) {
				// After a flush the pending payload came from a re-entered writer and
				// is newer than this call's; only fill a gap then.
				if ( null !== $payload && ( 0 === $attempt || null === $this->entries[ $key ][3] ) ) {
					$this->entries[ $key ][3] = $payload;
				}
				return;
			}
			if ( count( $this->entries ) < $this->capacity ) {
				break;
			}
			$this->flush();
		}
		$this->entries[ $key ] = array( get_current_blog_id(), $type, $id, $payload );
	}

	/**
	 * Whether this blog owes a write for the record.
	 *
	 * @param string $type Record type.
	 * @param int    $id   Record id.
	 * @return bool
	 */
	public function owes( string $type, int $id ): bool {
		return isset( $this->entries[ $this->key( $type, $id ) ] );
	}

	/**
	 * Forget this blog's pending write without writing it.
	 *
	 * @param string $type Record type.
	 * @param int    $id   Record id.
	 */
	public function drop( string $type, int $id ): void {
		unset( $this->entries[ $this->key( $type, $id ) ] );
	}

	/** Drain before writing so exceptions and reentrant saves cannot replay entries. */
	public function flush(): void {
		$entries       = $this->entries;
		$this->entries = array();
		foreach ( $entries as $entry ) {
			list( $blog, $type, $id, $payload ) = $entry;
			$switch                           = is_multisite() && get_current_blog_id() !== $blog;
			if ( $switch ) {
				switch_to_blog( $blog );
			}
			try {
				( $this->write )( $type, $id, $payload );
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}
	}

	/** Latch even an empty queue so subsequent saves write immediately. */
	public function flush_at_shutdown(): void {
		$this->shutdown_flushed = true;
		$this->flush();
	}

	/** Discard entries and the shutdown latch. Tests only. */
	public function reset(): void {
		$this->entries          = array();
		$this->shutdown_flushed = false;
	}

	/**
	 * Scope a record to its current blog.
	 *
	 * @param string $type Record type.
	 * @param int    $id   Record id.
	 * @return string
	 */
	private function key( string $type, int $id ): string {
		return get_current_blog_id() . ':' . $type . ':' . $id;
	}
}
