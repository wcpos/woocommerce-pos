<?php
/**
 * Variation collection writer.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WC_Product;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Enforces variation parents, nested routes, and wrapper documents. */
class Variation_Writer extends Null_Writer {
	/** Prepare a nested variation create. */
	public function prepare_create( array $meta, array $payload, callable $validate_tax_ids ) {
		$parent_id = $this->required_parent_id( $payload );
		if ( $parent_id instanceof WP_REST_Response ) {
			return $parent_id;
		}
		return array(
			'method' => 'POST',
			'route' => $meta['route'] . '/' . $parent_id . '/variations',
			'payload' => $payload,
			'context' => array( 'parent_id' => $parent_id ),
		);
	}

	/** Prepare a nested variation update. */
	public function prepare_update( array $meta, int $id, array $payload, callable $validate_tax_ids ) {
		$variation = $this->variation( $id );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		$parent_id = (int) $variation->get_parent_id();
		if ( array_key_exists( 'parent_id', $payload ) && ( ! is_int( $payload['parent_id'] ) || $parent_id !== $payload['parent_id'] ) ) {
			return $this->parent_mismatch();
		}
		return array(
			'method' => 'PUT',
			'route' => $meta['route'] . '/' . $parent_id . '/variations/' . $id,
			'payload' => $payload,
			'context' => array( 'parent_id' => $parent_id ),
		);
	}

	/** Confirm a born-twice variation still belongs to the requested parent. */
	public function validate_existing_create( int $id, array $payload, array $prepared ) {
		$variation = $this->variation( $id );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		return (int) $variation->get_parent_id() === (int) $prepared['context']['parent_id'] ? null : $this->parent_mismatch();
	}

	/** Delete through the stored parent's nested route. */
	public function delete( array $meta, int $id, array $mutation, callable $dispatch, callable $can_delete ) {
		$variation = $this->variation( $id );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		$route = $meta['route'] . '/' . (int) $variation->get_parent_id() . '/variations/' . $id;
		return $dispatch( $this->delete_request( $route, $id, true ) );
	}

	/** Build the targeted variation wrapper document. */
	public function document( array $meta, int $id, callable $default_document ) {
		$variation = $this->variation( $id );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		$payload  = ( new Product_Serializer() )->serialize( $variation, new WP_REST_Request( 'GET', '/' ) );
		$document = array(
			'id' => $id,
			'parent_id' => (int) $variation->get_parent_id(),
			'payload' => $payload,
		);
		if ( class_exists( Integrity_Digest::class ) ) {
			$digests = ( new Integrity_Digest() )->read_digests( array( $id ) );
			if ( isset( $digests[ $id ] ) ) {
				$document['_rxdb_digest'] = $digests[ $id ];
			}
		}
		return new WP_REST_Response( $document, 200 );
	}

	/** Put the stable UUID inside the variation payload wrapper. */
	public function build_response_document( array $bare, string $record_id, array $meta, int $id, callable $default_builder ): array {
		$payload         = isset( $bare['payload'] ) && is_array( $bare['payload'] ) ? $bare['payload'] : array();
		$bare['payload'] = Pos_Uuid::ensure_in_payload( $payload, $record_id );
		return $bare;
	}

	/** Require a live variable parent for create. */
	private function required_parent_id( array $payload ) {
		$parent_id = isset( $payload['parent_id'] ) && is_int( $payload['parent_id'] ) ? $payload['parent_id'] : 0;
		$parent    = $parent_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : false;
		if ( ! $parent instanceof WC_Product || $parent instanceof WC_Product_Variation
			|| ( method_exists( $parent, 'is_type' ) && ! $parent->is_type( 'variable' ) )
			|| ( method_exists( $parent, 'get_status' ) && 'trash' === $parent->get_status() ) ) {
			return new WP_REST_Response(
				array(
					'code' => 'woo_rxdb_sync_parent_required',
					'message' => 'Creating a variation requires a live parent product.',
				),
				428
			);
		}
		return $parent_id;
	}

	/** Build the stable parent mismatch response. */
	private function parent_mismatch(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code' => 'woo_rxdb_sync_parent_mismatch',
				'message' => 'payload parent_id does not match the stored variation parent.',
			),
			409
		);
	}

	/** Load only a real variation. */
	private function variation( int $id ) {
		$variation = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
		return $variation instanceof WC_Product_Variation
			? $variation
			: new WP_Error( 'woo_rxdb_sync_record_not_found', 'No variation for recordId.', array( 'status' => 404 ) );
	}
}
