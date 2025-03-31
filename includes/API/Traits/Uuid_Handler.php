<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Data;
use WC_Meta_Data;
use WC_Order_Item;
use WCPOS\WooCommercePOS\Logger;
use WP_User;
use WC_Product;
use WC_Product_Variation;
use WC_Abstract_Order;
use Automattic\WooCommerce\Utilities\OrderUtil;
use function get_user_meta;
use function update_user_meta;
use function delete_user_meta;
use function delete_term_meta;
use function add_term_meta;
use function wp_cache_add;
use function wp_cache_delete;

/**
 * Trait Uuid_Handler.
 *
 * Ensures each WooCommerce record (products, orders, customers etc)
 * has a consistent unique UUID stored in the database.
 */
trait Uuid_Handler {

	/**
	 * Acquire a lock using the persistent object cache.
	 *
	 * @param string $lock_key Unique key for the lock.
	 * @param int    $timeout  Timeout in seconds.
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquire_lock( string $lock_key, int $timeout = 10 ): bool {
		$attempts   = 0;
		$sleep_time = 100000; // 100ms in microseconds.
		// Try every 100ms until timeout.
		while ( $attempts < $timeout * 10 ) {
			// wp_cache_add() returns true if the key did not exist.
			if ( wp_cache_add( $lock_key, true, 'wc_pos_locks', $timeout ) ) {
				return true;
			}
			usleep( $sleep_time );
			$attempts++;
		}
		return false;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $lock_key Unique key for the lock.
	 * @return void
	 */
	private function release_lock( string $lock_key ): void {
		wp_cache_delete( $lock_key, 'wc_pos_locks' );
	}

	/**
	 * Make sure the WC Data Object has a UUID.
	 *
	 * @param WC_Data $object
	 * @return void
	 */
	private function maybe_add_post_uuid( WC_Data $object ): void {
		// Use object_type to ensure uniqueness across different record types.
		$lock_key = 'wc_pos_uuid_' . $object->get_type() . '_' . $object->get_id();
		if ( ! $this->acquire_lock( $lock_key, 10 ) ) {
			Logger::log( "Unable to acquire lock for post UUID update for {$object->get_type()} id " . $object->get_id() );
			return;
		}
		try {
			$meta_data = $object->get_meta_data();
			$uuids     = array_filter(
				$meta_data,
				function ( WC_Meta_Data $meta ) {
					return '_woocommerce_pos_uuid' === $meta->key;
				}
			);

			$uuid_values = array_map(
				function ( WC_Meta_Data $meta ) {
					return $meta->value;
				},
				$uuids
			);

			// Re-index to ensure sequential keys.
			$uuid_values = array_values( $uuid_values );

			// If more than one UUID exists, keep the first and delete the rest.
			if ( count( $uuid_values ) > 1 ) {
				$first_uuid_key = key( $uuids );
				foreach ( $uuids as $key => $uuid_meta ) {
					if ( $key === $first_uuid_key ) {
						continue;
					}
					$object->delete_meta_data_by_mid( $uuid_meta->id );
				}
				$uuid_values = array( reset( $uuids )->value );
			}

			$should_update_uuid = empty( $uuid_values )
				|| ( isset( $uuid_values[0] ) && ! Uuid::isValid( $uuid_values[0] ) )
				|| ( isset( $uuid_values[0] ) && $this->uuid_postmeta_exists( $uuid_values[0], $object ) );

			if ( $should_update_uuid ) {
				$object->update_meta_data( '_woocommerce_pos_uuid', $this->create_uuid() );
			}
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/**
	 * Ensure the WP_User has a valid UUID.
	 *
	 * @param WP_User $user
	 * @return void
	 */
	private function maybe_add_user_uuid( WP_User $user ): void {
		$lock_key = 'wc_pos_uuid_user_' . $user->ID;
		if ( ! $this->acquire_lock( $lock_key, 10 ) ) {
			Logger::log( "Unable to acquire lock for user UUID update for user id " . $user->ID );
			return;
		}
		try {
			$uuids = get_user_meta( $user->ID, '_woocommerce_pos_uuid', false );

			// If more than one UUID exists, keep the first and remove the rest.
			if ( count( $uuids ) > 1 ) {
				for ( $i = 1; $i < count( $uuids ); $i++ ) {
					delete_user_meta( $user->ID, '_woocommerce_pos_uuid', $uuids[ $i ] );
				}
				$uuids = array( $uuids[0] );
			}

			$should_update_uuid = empty( $uuids )
				|| ( isset( $uuids[0] ) && ! Uuid::isValid( $uuids[0] ) )
				|| ( isset( $uuids[0] ) && $this->uuid_usermeta_exists( $uuids[0], $user->ID ) );

			if ( $should_update_uuid ) {
				update_user_meta( $user->ID, '_woocommerce_pos_uuid', $this->create_uuid() );
			}
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/**
	 * Ensure the WC_Order_Item has a valid UUID.
	 *
	 * @param WC_Order_Item $item
	 * @return void
	 */
	private function maybe_add_order_item_uuid( WC_Order_Item $item ): void {
		$lock_key = 'wc_pos_uuid_order_item_' . $item->get_id();
		if ( ! $this->acquire_lock( $lock_key, 10 ) ) {
			Logger::log( "Unable to acquire lock for order item UUID update for order item id " . $item->get_id() );
			return;
		}
		try {
			$uuid = $item->get_meta( '_woocommerce_pos_uuid' );
			if ( ! $uuid ) {
				$uuid = Uuid::uuid4()->toString();
				$item->update_meta_data( '_woocommerce_pos_uuid', $uuid );
				$item->save_meta_data();
			}
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/**
	 * Ensure the term has a valid UUID and return it.
	 *
	 * @param object $term
	 * @return string
	 */
	private function get_term_uuid( $term ): string {
		$lock_key = 'wc_pos_uuid_term_' . $term->term_id;
		if ( ! $this->acquire_lock( $lock_key, 10 ) ) {
			Logger::log( "Unable to acquire lock for term UUID update for term id " . $term->term_id );
			$uuids = get_term_meta( $term->term_id, '_woocommerce_pos_uuid', false );
			return ( ! empty( $uuids ) && Uuid::isValid( $uuids[0] ) ) ? $uuids[0] : $this->create_uuid();
		}
		try {
			$uuids = get_term_meta( $term->term_id, '_woocommerce_pos_uuid', false );

			if ( count( $uuids ) > 1 ) {
				for ( $i = 1; $i < count( $uuids ); $i++ ) {
					delete_term_meta( $term->term_id, '_woocommerce_pos_uuid', $uuids[ $i ] );
				}
				$uuids = array( $uuids[0] );
			}

			$should_update_uuid = empty( $uuids )
				|| ( isset( $uuids[0] ) && ! Uuid::isValid( $uuids[0] ) )
				|| ( isset( $uuids[0] ) && $this->uuid_termmeta_exists( $uuids[0], $term->term_id ) );

			if ( $should_update_uuid ) {
				$uuid = $this->create_uuid();
				add_term_meta( $term->term_id, '_woocommerce_pos_uuid', $uuid, true );
				return $uuid;
			}

			return $uuids[0];
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/**
	 * Generate a new UUID.
	 *
	 * @return string
	 */
	private function create_uuid(): string {
		try {
			return Uuid::uuid4()->toString();
		} catch ( Exception $e ) {
			Logger::log( 'UUID generation failed: ' . $e->getMessage() );
			return 'fallback-uuid-' . time();
		}
	}

	/**
	 * Check if the given UUID is unique.
	 *
	 * @param string  $uuid   The UUID to check.
	 * @param WC_Data $object The WooCommerce data object.
	 * @return bool True if unique, false otherwise.
	 */
	private function uuid_postmeta_exists( string $uuid, WC_Data $object ): bool {
		global $wpdb;

		if ( $object instanceof WC_Abstract_Order && class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s AND order_id != %d LIMIT 1",
					$uuid,
					$object->get_id()
				)
			);
		} else {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s AND post_id != %d LIMIT 1",
					$uuid,
					$object->get_id()
				)
			);
		}

		return (bool) $result;
	}

	/**
	 * Retrieve order IDs by UUID.
	 *
	 * @param string $uuid
	 * @return array
	 */
	private function get_order_ids_by_uuid( string $uuid ) {
		global $wpdb;

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$result = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s",
					$uuid
				)
			);
		} else {
			$result = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s",
					$uuid
				)
			);
		}

		return $result;
	}

	/**
	 * Check if the given UUID already exists for any user.
	 *
	 * @param string $uuid       The UUID to check.
	 * @param int    $exclude_id The user ID to exclude.
	 * @return bool True if unique, false otherwise.
	 */
	private function uuid_usermeta_exists( string $uuid, int $exclude_id ): bool {
		global $wpdb;
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s AND user_id != %d LIMIT 1",
				$uuid,
				$exclude_id
			)
		);

		return (bool) $result;
	}

	/**
	 * Check if the given UUID already exists for any term.
	 *
	 * @param string $uuid            The UUID to check.
	 * @param int    $exclude_term_id The term ID to exclude.
	 * @return bool True if unique, false otherwise.
	 */
	private function uuid_termmeta_exists( string $uuid, int $exclude_term_id ): bool {
		global $wpdb;
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->termmeta} WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s AND term_id != %d LIMIT 1",
				$uuid,
				$exclude_term_id
			)
		);

		return (bool) $result;
	}
}