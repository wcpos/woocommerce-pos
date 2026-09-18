<?php
/**
 * Create identity proof shared by fresh writes and poisoned retries.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\Interfaces\Collection_Writer_Interface;
use WP_Error;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

/**
 * A created record must own its client UUID before the mutation is finalized,
 * or the till keys on the wrong record. A retry re-enters the same proof against
 * a poison checkpoint. ADR 0038 governs WHEN identity is re-proved; this module
 * only moves WHERE the sequence lives.
 *
 * after_create: fresh lane, after the checkpoint attempt and before the proof.
 * after_identity: fresh lane, after the proof and before finalization.
 * after_recovery: retry lane, after the proof and before finalization.
 * after_update: update lane only, called by the controller, not this module.
 */
final class Create_Identity {
	/** @var mixed Duck-typed mutation store; tests inject an in-memory implementation. */
	private $store;

	/** Use the same store as the write controller. */
	public function __construct( $store ) {
		$this->store = $store;
	}

	/** @return int|WP_Error Created id after proof and finalization, or the protocol error. */
	public function stamp( array $meta, array $m, int $new_id, int $response_status, Collection_Writer_Interface $writer ) {
		$checkpointed = $this->store->mark_poison( $m['mutationId'], $new_id, $response_status );
		$writer->after_create( $new_id, $m['payload'] );
		$identity_error = $this->prove( $meta, $new_id, $m['recordId'] );
		// Attempt identity even when the checkpoint failed: the known record must retain its UUID.
		if ( ! $checkpointed ) {
			$this->store->mark_indeterminate( $m['mutationId'], $new_id, $response_status );
			return $this->finalize_error();
		}
		if ( $identity_error ) {
			return $identity_error;
		}
		$writer->after_identity( $new_id, $m['payload'] );
		if ( ! $this->store->finalize_poison( $m['mutationId'], $new_id ) ) {
			return $this->finalize_error();
		}
		return $new_id;
	}

	/** @return array{id: int, status: int}|WP_Error Recovered id and recorded status, or the protocol error. */
	public function recover( array $meta, array $m, array $hit, Collection_Writer_Interface $writer ) {
		$remote_id = (int) ( $hit['remote_id'] ?? 0 );
		$record_uuid = (string) ( $hit['record_uuid'] ?? '' );
		if ( $record_uuid !== $m['recordId'] ) {
			return $this->conflict_error();
		}
		if ( 'create' !== ( $hit['operation'] ?? '' ) || $remote_id <= 0 ) {
			return $this->persistence_error();
		}
		$resolved = $this->store->resolve_id_by_uuid( $meta['id_type'], $record_uuid, $meta );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( $resolved > 0 && $resolved !== $remote_id ) {
			return $this->persistence_error();
		}
		$identity_error = $this->prove( $meta, $remote_id, $record_uuid );
		if ( $identity_error ) {
			return $identity_error;
		}
		$writer->after_recovery( $remote_id, $m['payload'] );
		if ( ! $this->store->finalize_poison( $m['mutationId'], $remote_id ) ) {
			return $this->finalize_error();
		}
		return array(
			'id' => $remote_id,
			'status' => isset( $hit['response_status'] ) ? (int) $hit['response_status'] : 201,
		);
	}

	/** Persist the UUID and prove it resolves back to this exact record. */
	private function prove( array $meta, int $id, string $record_uuid ): ?WP_Error {
		if ( ! $this->store->persist_uuid( $meta['id_type'], $id, $record_uuid ) ) {
			return $this->persistence_error();
		}
		$resolved = $this->store->resolve_id_by_uuid( $meta['id_type'], $record_uuid, $meta );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		return $resolved !== $id ? $this->persistence_error() : null;
	}

	/** Same code and wording for either lane's failed identity proof. */
	private function persistence_error(): WP_Error {
		return new WP_Error( 'woo_rxdb_sync_identity_persistence_failed', 'Unable to persist created record identity.', array( 'status' => 500 ) );
	}

	/** A retry cannot change the checkpoint's client identity. */
	private function conflict_error(): WP_Error {
		return new WP_Error( 'woo_rxdb_sync_identity_conflict', 'recordId disagrees with the stored mutation identity.', array( 'status' => 422 ) );
	}

	/** Keep the controller's update/delete finalization error wording unchanged. */
	private function finalize_error(): WP_Error {
		return new WP_Error( 'woo_rxdb_sync_finalize_failed', 'Woo write succeeded but mutation finalization failed; retry the same mutationId.', array( 'status' => 500 ) );
	}
}
