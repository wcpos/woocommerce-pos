<?php
/**
 * Pass-through collection writer.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WCPOS\WooCommercePOS\Interfaces\Collection_Writer_Interface;
use WP_REST_Request;

/** Provides the unmodified lifecycle used by ordinary wc/v3 collections. */
class Null_Writer implements Collection_Writer_Interface {
	/** Prepare an unchanged create. */
	public function prepare_create( array $meta, array $payload, callable $validate_tax_ids ) {
		return array(
			'method' => 'POST',
			'route' => $meta['route'],
			'payload' => $payload,
			'context' => array(),
		);
	}

	/** Prepare an unchanged update. */
	public function prepare_update( array $meta, int $id, array $payload, callable $validate_tax_ids ) {
		return array(
			'method' => 'PUT',
			'route' => $meta['route'] . '/' . $id,
			'payload' => $payload,
			'context' => array(),
		);
	}

	/** Accept an existing target unchanged. */
	public function validate_existing_create( int $id, array $payload, array $prepared ) {
		return null;
	}

	/** Forward without collection hooks. */
	public function forward( array $prepared, callable $forward ) {
		return $forward( $prepared['method'], $prepared['route'], $prepared['payload'] );
	}

	/** No-op for pass-through persistence. */
	public function persist( string $phase, int $id, array $payload, array $current = array(), array $response_data = array(), array $context = array() ): void {
	}

	/** Delete a generic collection record, honouring the envelope's `force` flag. */
	public function delete( array $meta, int $id, array $mutation, callable $dispatch, callable $can_delete ) {
		$force = \array_key_exists( 'force', $mutation ) ? (bool) $mutation['force'] : $this->default_force( $meta );

		return $dispatch( $this->delete_request( $meta['route'] . '/' . $id, $id, $force ) );
	}

	/**
	 * The delete mode when the envelope carries no `force`.
	 *
	 * Trash where WooCommerce supports it, permanent delete where it does not. Post-backed
	 * collections (products, coupons) trash unless the site has disabled trash
	 * (`EMPTY_TRASH_DAYS` of 0 — wc/v3 answers `force=false` with a 501 then). Term-backed
	 * (categories, brands, tags) and user-backed (customers) records cannot be trashed, so
	 * their only delete is permanent. An explicit envelope value always wins: a `force:false`
	 * on a term is forwarded as-is and WooCommerce's 501 is the honest answer. Variations
	 * never reach this method — {@see Variation_Writer::delete()} forces, as they cannot trash.
	 *
	 * @param array $meta Collection meta (`post_type` / `taxonomy` / `id_type`).
	 */
	protected function default_force( array $meta ): bool {
		if ( empty( $meta['post_type'] ) ) {
			return true;
		}

		return ! ( \defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0 );
	}

	/** Use the shared generic document reader. */
	public function document( array $meta, int $id, callable $default_document ) {
		return $default_document( $meta, $id, array() );
	}

	/** Use the shared generic response builder. */
	public function build_response_document( array $bare, string $record_id, array $meta, int $id, callable $default_builder ): array {
		return $default_builder( $bare, $record_id, $meta, $id );
	}

	/** Build a delete request with authoritative route parameters. */
	protected function delete_request( string $route, int $id, bool $force ): WP_REST_Request {
		$request = new WP_REST_Request( 'DELETE', $route );
		$request->set_param( 'id', $id );
		$request->set_param( 'force', $force );
		return $request;
	}
}
