<?php
/**
 * Customer collection writer.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;

/** Adapts customer tax IDs and walk-in billing emails around wc/v3. */
class Customer_Writer extends Null_Writer {
	/** Prepare a customer create. */
	public function prepare_create( array $meta, array $payload, callable $validate_tax_ids ) {
		$error = $validate_tax_ids( $payload );
		return is_wp_error( $error ) ? $error : parent::prepare_create( $meta, $this->prepare_payload( $payload ), $validate_tax_ids );
	}

	/** Prepare a customer update. */
	public function prepare_update( array $meta, int $id, array $payload, callable $validate_tax_ids ) {
		$error = $validate_tax_ids( $payload );
		return is_wp_error( $error ) ? $error : parent::prepare_update( $meta, $id, $this->prepare_payload( $payload ), $validate_tax_ids );
	}

	/** Persist customer tax IDs after successful writes and poison recovery. */
	public function persist( string $phase, int $id, array $payload, array $current = array(), array $response_data = array(), array $context = array() ): void {
		if ( ! in_array( $phase, array( 'create_before_identity', 'create_recovery', 'update' ), true ) || $id <= 0 || ! is_array( $payload['tax_ids'] ?? null ) ) {
			return;
		}
		( new Tax_Id_Writer() )->write_for_user( $id, $payload['tax_ids'] );
		do_action( 'woocommerce_update_customer', $id, new \WC_Customer( $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/** Remove customer-only fields from the wc/v3 forward. */
	private function prepare_payload( array $payload ): array {
		unset( $payload['tax_ids'] );
		return ( new Order_Write_Payload() )->without_empty_billing_email( $payload );
	}
}
