<?php
/**
 * Uuid_Handler.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\V1\Traits;

use Ramsey\Uuid\Uuid;
use WC_Abstract_Order;
use WC_Data;
use WC_Order_Item;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_User;

/**
 * Trait Uuid_Handler.
 *
 * Ensures each WooCommerce record (products, orders, customers etc)
 * has a consistent unique UUID stored in the database.
 */
trait Uuid_Handler {
	/**
	 * Acquire the order-item UUID datastore lock.
	 *
	 * @param string $lock_key Unique key for the lock.
	 * @param int    $timeout  Timeout in seconds.
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquire_order_item_uuid_lock( string $lock_key, int $timeout = 10 ): bool {
		global $wpdb;

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, $timeout ) );
		return '1' === (string) $acquired;
	}

	/**
	 * Release the order-item UUID datastore lock.
	 *
	 * @param string $lock_key Unique key for the lock.
	 * @return void
	 */
	private function release_order_item_uuid_lock( string $lock_key ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
	}

	/**
	 * Make sure the WC Data Object has a UUID.
	 *
	 * @param WC_Data $object The WooCommerce data object.
	 * @return void
	 */
	private function maybe_add_post_uuid( WC_Data $object ): void {
		$collides = $object instanceof WC_Abstract_Order
			? array( 'WCPOS\\WooCommercePOS\\Sync\\Pos_Uuid', 'uuid_owned_by_other_order' )
			: array( 'WCPOS\\WooCommercePOS\\Sync\\Pos_Uuid', 'uuid_owned_by_other' );

		Pos_Uuid::ensure_uuid( $object, array( 'collides' => $collides ) );
	}

	/**
	 * Ensure the WP_User has a valid UUID.
	 *
	 * @param WP_User $user The WordPress user object.
	 * @return void
	 */
	private function maybe_add_user_uuid( WP_User $user ): void {
		Pos_Uuid::ensure_user_uuid( $user );
	}

	/**
	 * Ensure the WC_Order_Item has a valid UUID.
	 *
	 * @param WC_Order_Item $item The order item object.
	 * @return void
	 */
	private function maybe_add_order_item_uuid( WC_Order_Item $item ): void {
		if ( Pos_Uuid::is_uuid( $item->get_meta( Pos_Uuid::META_KEY ) ) ) {
			return;
		}

		$lock_key = 'wc_pos_uuid_order_item_' . $item->get_id();
		if ( ! $this->acquire_order_item_uuid_lock( $lock_key, 10 ) ) {
			Logger::log( 'Unable to acquire lock for order item UUID update for order item id ' . $item->get_id() );
			return;
		}
		try {
			// Persist any pending meta, then check the STORED uuid directly —
			// a full read_meta_data(true) reload would clobber sibling in-memory
			// meta on lanes where the datastore cache lags (HPOS misc `_sku`).
			$item->save_meta_data();
			$uuid = wc_get_order_item_meta( $item->get_id(), Pos_Uuid::META_KEY, true );
			if ( ! Pos_Uuid::is_uuid( $uuid ) ) {
				$uuid = Uuid::uuid4()->toString();
				$item->update_meta_data( Pos_Uuid::META_KEY, $uuid );
				$item->save_meta_data();
			} elseif ( $uuid !== $item->get_meta( Pos_Uuid::META_KEY ) ) {
				// A concurrent request minted first; converge the stale in-memory
				// item on the stored winner so the served payload carries it.
				$item->update_meta_data( Pos_Uuid::META_KEY, $uuid );
			}
		} finally {
			$this->release_order_item_uuid_lock( $lock_key );
		}
	}

	/**
	 * Ensure the term has a valid UUID and return it.
	 *
	 * @param object $term The term object.
	 * @return string
	 */
	private function get_term_uuid( $term ): string {
		return Pos_Uuid::ensure_term_uuid( $term );
	}

	/**
	 * Retrieve order IDs by UUID.
	 *
	 * @param string $uuid The UUID to search for.
	 * @return array
	 */
	private function get_order_ids_by_uuid( string $uuid ) {
		return Pos_Uuid::get_order_ids_by_uuid( $uuid );
	}
}
