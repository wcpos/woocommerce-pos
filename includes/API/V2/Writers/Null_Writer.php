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
use WP_REST_Response;

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
		$route = $meta['route'] . '/' . $id;
		if ( isset( $mutation['force'] ) ) {
			return $dispatch( $this->delete_request( $route, $id, (bool) $mutation['force'] ) );
		}

		$response = $dispatch( $this->delete_request( $route, $id, false ) );

		return $this->trash_not_supported( $response ) ? $dispatch( $this->delete_request( $route, $id, true ) ) : $response;
	}

	/**
	 * Whether wc/v3 refused a trash (`force=false`) because the record cannot be trashed.
	 *
	 * A delete without `force` asks WooCommerce to trash and lets WooCommerce decide whether it
	 * can: products and coupons trash unless the site disabled it (`EMPTY_TRASH_DAYS`) or an
	 * extension opted the type out (`woocommerce_rest_{type}_object_trashable`); terms and users
	 * have no trash at all. Re-deriving that predicate here would drift from WooCommerce's, so the
	 * writer reads the answer off the 501 and only then deletes permanently. The refused attempt
	 * has no side effects — every wc/v3 controller checks `force` before touching the record. An
	 * explicit envelope value is never retried: `force:false` on a term returns the 501 as-is.
	 * Variations never reach this path — {@see Variation_Writer::delete()} forces, as they cannot trash.
	 *
	 * @param mixed $response The forwarded delete's response.
	 */
	protected function trash_not_supported( $response ): bool {
		if ( ! $response instanceof WP_REST_Response || 501 !== $response->get_status() ) {
			return false;
		}
		$data = $response->get_data();

		return \is_array( $data ) && 'woocommerce_rest_trash_not_supported' === ( $data['code'] ?? '' );
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
