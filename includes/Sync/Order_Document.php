<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The uuid-stop fail-closed message carries only a numeric order id, not rendered output.

/**
 * THE complete-order-document builder (#424): the envelope the order read
 * surface ships — the batch custom pull — assembled in exactly one place, keyed
 * by the order's stable uuid (P0-1), with the uuid guard's single fail-closed
 * reaction (Order_Uuid_Exception).
 *
 * The re-key (ADR 0021 trap): `id` is the record's _woocommerce_pos_uuid — the
 * SAME identity the client stores under — never woo-order:<id>. A numeric-keyed
 * document would duplicate the uuid-keyed row client-side.
 */
final class Order_Document {
	/**
	 * Read the order's stable uuid from a payload's meta, failing closed.
	 * The IDENTITY payload must be the full serialized payload (the serializer
	 * stamped it via the woocommerce_pos_sync_serialized_order filter).
	 *
	 * @param array $identity_payload The FULL serialized payload (uuid source of truth).
	 * @param int   $order_id         The numeric Woo order id (for the error message).
	 *
	 * @throws Order_Uuid_Exception when the payload carries no valid uuid.
	 */
	public static function require_uuid( array $identity_payload, int $order_id ): string {
		$uuid = Pos_Uuid::read_valid_uuid_from_meta( $identity_payload['meta_data'] ?? array() );
		if ( '' === $uuid ) {
			throw Order_Uuid_Exception::for_order( $order_id );
		}
		return $uuid;
	}

	/**
	 * Build the complete order document envelope.
	 *
	 * @param array  $identity_payload The FULL serialized payload (uuid source of truth).
	 * @param array  $client_payload   The payload actually shipped.
	 * @param int    $order_id         The numeric Woo order id (wooOrderId).
	 * @param string $revision         The document revision (index row's, or the canonical fallback).
	 * @param array  $checkpoint       The per-document checkpoint (updatedAtGmt/orderId/revision/sequence).
	 * @param bool   $partial          Whether $client_payload is a partial fieldset.
	 * @param string $source           'custom-pull' | 'skeleton' | 'snapshot'.
	 *
	 * @throws Order_Uuid_Exception when the identity payload carries no valid uuid.
	 */
	public static function build( array $identity_payload, array $client_payload, int $order_id, string $revision, array $checkpoint, bool $partial, string $source ): array {
		return array(
			'id' => self::require_uuid( $identity_payload, $order_id ),
			'wooOrderId' => $order_id,
			'payload' => $client_payload,
			'sync' => array(
				'revision' => $revision,
				'checkpoint' => $checkpoint,
				'partial' => $partial,
				'source' => $source,
			),
			'local' => array(
				'dirty' => false,
				'pendingMutationIds' => array(),
			),
		);
	}
}
