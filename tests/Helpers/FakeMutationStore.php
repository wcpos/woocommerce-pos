<?php
/**
 * Shared in-memory mutation store for sync tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions -- Preserve the controller suite's fake-store vocabulary.

/** In-memory mutation store — lets the controller's apply logic be tested without a DB. */
final class Fake_Mutation_Store {
	/** @var array<string,array> mutationId => {remote_id, operation, record_uuid, status} that lookup() returns */
	public array $lookups = array();
	/** resolve_id_by_uuid() returns this (0 = none, an int id, or a WP_Error for an ambiguous uuid) */
	public $resolve = 0;
	/** resolve_id_by_uuid() return values consumed before falling back to $resolve */
	public array $resolveResults = array();
	/** lookup() return values consumed before falling back to $lookups */
	public array $lookupResults = array();
	/** @var array[] persist_uuid() calls */
	public array $persisted = array();
	/** @var array[] persist_order_audit_meta() calls: {id, meta, created_via} */
	public array $auditMeta = array();
	/** @var string[] mutationIds reserve() was called for */
	public array $reserved = array();
	/** @var array<string,int> mutationId => remote_id finalized */
	public array $finalized = array();
	public array $applied = array();
	public array $poisoned = array();
	public array $fingerprints = array();
	public bool $finalizeOk = true;
	public bool $poisonOk = true;
	public bool $indeterminateOk = true;
	public bool $persistUuidOk = true;
	/** @var string[] mutationIds released */
	public array $released = array();
	/** consumed per reserve() call; empty ⇒ true */
	public array $reserveResults = array();
	/** what reclaim_stale() returns */
	public bool $reclaimOk = false;
	/** what acquire_record_lock() returns (false ⇒ couldn't serialise) */
	public bool $recordLockOk = true;
	/** @var string[] ordered trace of acquire/release_record_lock + apply, to assert the lock wraps apply */
	public array $lockTrace = array();
	/** @var string[] Shared store/writer trace for create identity ordering. */
	public array $identity_trace = array();

	public function lookup( string $collection, string $mutation_id ): ?array {
		if ( array() !== $this->lookupResults ) {
			return array_shift( $this->lookupResults );
		}
		return $this->lookups[ $mutation_id ] ?? null;
	}
	public function reserve( string $collection, string $mutation_id, string $record_uuid, string $operation, string $fingerprint = '' ): bool {
		$this->reserved[] = $mutation_id;
		$this->fingerprints[ $mutation_id ] = $fingerprint;
		$reserved = isset( $this->lookups[ $mutation_id ] ) ? false : ( array_shift( $this->reserveResults ) ?? true );
		if ( ! isset( $this->lookups[ $mutation_id ] ) ) {
			$this->lookups[ $mutation_id ] = array(
				'collection' => $collection,
				'remote_id' => 0,
				'operation' => $operation,
				'record_uuid' => $record_uuid,
				'status' => 'pending',
				'fingerprint' => $fingerprint,
			);
		}
		return $reserved;
	}
	public function mark_applied( string $mutation_id, int $remote_id, int $response_status ): bool {
		$this->applied[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['remote_id'] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'applied';
		$this->lookups[ $mutation_id ]['response_status'] = $response_status;
		return true;
	}
	public function mark_poison( string $mutation_id, int $remote_id, int $response_status = 201 ): bool {
		$this->identity_trace[] = 'mark_poison';
		if ( ! $this->poisonOk ) {
			return false;
		}
		$this->poisoned[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['remote_id'] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'poison';
		$this->lookups[ $mutation_id ]['response_status'] = $response_status;
		return true;
	}
	public function mark_indeterminate( string $mutation_id, int $remote_id, int $response_status ): bool {
		$this->identity_trace[] = 'mark_indeterminate';
		if ( ! $this->indeterminateOk ) {
			return false;
		}
		$this->lookups[ $mutation_id ]['remote_id'] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'blocked';
		$this->lookups[ $mutation_id ]['response_status'] = $response_status;
		return true;
	}
	public function finalize( string $mutation_id, int $remote_id ): bool {
		if ( ! $this->finalizeOk ) {
			return false;
		}
		$this->finalized[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'done';
		return true;
	}
	public function finalize_poison( string $mutation_id, int $remote_id ): bool {
		$this->identity_trace[] = 'finalize_poison';
		if ( ! $this->finalizeOk ) {
			$this->lookups[ $mutation_id ]['status'] = 'poison';
			return false;
		}
		$this->finalized[ $mutation_id ] = $remote_id;
		$this->lookups[ $mutation_id ]['status'] = 'done';
		return true;
	}
	public function release( string $mutation_id ): void {
		$this->released[] = $mutation_id;
		if ( 'pending' === ( $this->lookups[ $mutation_id ]['status'] ?? '' ) ) {
			unset( $this->lookups[ $mutation_id ] );
		}
	}
	public function reclaim_stale( string $mutation_id, int $ttl ): bool {
		if ( 'create' === ( $this->lookups[ $mutation_id ]['operation'] ?? '' ) ) {
			return false;
		}
		if ( $this->reclaimOk ) {
			unset( $this->lookups[ $mutation_id ] );
			return true;
		}
		return false;
	}
	public function reservation_ttl(): int {
		return 900;
	}
	public function acquire_record_lock( string $collection, string $uuid ): bool {
		$this->lockTrace[] = 'acquire';
		return $this->recordLockOk;
	}
	public function release_record_lock( string $collection, string $uuid ): void {
		$this->lockTrace[] = 'release';
	}
	public function resolve_id_by_uuid( string $id_type, string $uuid, array $opts = array() ) {
		$this->identity_trace[] = 'resolve_id_by_uuid';
		$this->lockTrace[] = 'resolve'; // runs INSIDE apply → must fall between acquire and release
		if ( ! empty( $this->resolveResults ) ) {
			return array_shift( $this->resolveResults );
		}
		return $this->resolve;
	}
	public function persist_uuid( string $id_type, int $id, string $uuid ): bool {
		$this->identity_trace[] = 'persist_uuid';
		$this->persisted[] = compact( 'id_type', 'id', 'uuid' );
		if ( $this->persistUuidOk ) {
			$this->resolve = $id;
		}
		return $this->persistUuidOk;
	}
	public function persist_order_audit_meta( int $id, array $meta, string $created_via = '' ): void {
		$this->auditMeta[] = compact( 'id', 'meta', 'created_via' );
		$order = wc_get_order( $id );
		if ( $order ) {
			foreach ( $meta as $key => $value ) {
				$order->update_meta_data( $key, $value );
			}
			$order->set_created_via( $created_via );
			$order->save();
		}
	}
}
