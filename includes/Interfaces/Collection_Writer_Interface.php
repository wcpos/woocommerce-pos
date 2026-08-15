<?php
/**
 * Collection writer lifecycle contract.
 *
 * @package WCPOS\WooCommercePOS\Interfaces
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\Interfaces;

/** Adapts collection behavior around the controller-owned mutation protocol. */
interface Collection_Writer_Interface {
	/** Prepare a create payload and route. */
	public function prepare_create( array $meta, array $payload, callable $validate_tax_ids );

	/** Prepare an update payload and route. */
	public function prepare_update( array $meta, int $id, array $payload, callable $validate_tax_ids );

	/** Validate and repair an already-existing create target. */
	public function validate_existing_create( int $id, array $payload, array $prepared );

	/** Forward a prepared write within collection hooks. */
	public function forward( array $prepared, callable $forward );

	/** Persist collection data at a named create/update lifecycle phase. */
	public function persist( string $phase, int $id, array $payload, array $current = array(), array $response_data = array(), array $context = array() ): void;

	/** Execute the collection-specific delete forward. */
	public function delete( array $meta, int $id, array $mutation, callable $dispatch, callable $can_delete );

	/** Read the collection's authoritative response document. */
	public function document( array $meta, int $id, callable $default_document );

	/** Shape a bare document for the mutation response. */
	public function build_response_document( array $bare, string $record_id, array $meta, int $id, callable $default_builder ): array;
}
