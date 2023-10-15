<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use Exception;
use function get_user_meta;
use Ramsey\Uuid\Uuid;
use function update_user_meta;
use WC_Data;
use WC_Meta_Data;
use WC_Order_Item;
use WCPOS\WooCommercePOS\Logger;
use WP_User;

trait Uuid_Handler {
	/**
	 * Make sure the WC Data Object has a uuid.
	 */
	private function maybe_add_post_uuid( WC_Data $object ): void {
		$meta_data = $object->get_meta_data();

		$uuids = array_filter( $meta_data, function( WC_Meta_Data $meta ) {
			return '_woocommerce_pos_uuid' === $meta->key;
		});
		
		$uuid_values = array_map( function( WC_Meta_Data $meta ) {
			return $meta->value;
		}, $uuids);
		
		// If there is no uuid, add one, i.e., new product
		if ( empty( $uuid_values ) ) {
			$object->update_meta_data( '_woocommerce_pos_uuid', $this->create_uuid() );
		}

		// Check if there's more than one uuid, if so, delete and regenerate
		if ( \count( $uuid_values ) > 1 ) {
			foreach ( $uuids as $uuid_meta ) {
				$object->delete_meta_data( $uuid_meta->key );
			}
			$object->update_meta_data( '_woocommerce_pos_uuid', $this->create_uuid() );
		}
	}
	

	/**
	 * @param WP_User $user
	 *
	 * @return void
	 */
	private function maybe_add_user_uuid( WP_User $user ): void {
		$uuids = get_user_meta( $user->ID, '_woocommerce_pos_uuid', false );

		if ( empty( $uuids ) || empty( $uuids[0] ) ) {
			update_user_meta( $user->ID, '_woocommerce_pos_uuid', $this->create_uuid() );
		}
	}

	/**
	 * @TODO: this is called from the order class, so it doesn't have a sanity check yet
	 *
	 * @param WC_Order_Item $item
	 *
	 * @return void
	 */
	private function maybe_add_order_item_uuid( WC_Order_Item $item ): void {
		$uuid = $item->get_meta( '_woocommerce_pos_uuid' );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			$item->update_meta_data( '_woocommerce_pos_uuid', $uuid );
			$item->save_meta_data();
		}
	}

	/**
	 * @TODO: sanity check
	 *
	 * @param object $item
	 *
	 * @return string
	 */
	private function get_term_uuid( object $item ): string {
		$uuid = get_term_meta( $item->term_id, '_woocommerce_pos_uuid', true );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			add_term_meta( $item->term_id, '_woocommerce_pos_uuid', $uuid, true );
		}

		return $uuid;
	}

	/**
	 * @return string
	 */
	private function create_uuid(): string {
		try {
			return Uuid::uuid4()->toString();
		} catch ( Exception $e ) {
			// Log the error message
			Logger::log( 'UUID generation failed: ' . $e->getMessage() );

			// Return a fallback value
			return 'fallback-uuid-' . time();
		}
	}
}
