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

/**
 *
 */
trait Uuid_Handler {
	/**
	 * Make sure the WC Data Object has a uuid.
	 */
	private function maybe_add_post_uuid( WC_Data $object ): void {
		$meta_data = $object->get_meta_data();

		$uuids = array_filter(
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

		// Re-index the array to ensure sequential keys starting from 0.
		$uuid_values = array_values( $uuid_values );

		// Check if there's more than one uuid, if so, keep the first and delete the rest.
		if ( count( $uuid_values ) > 1 ) {
			$first_uuid_key = key( $uuids ); // Get the key of the first UUID.

			foreach ( $uuids as $key => $uuid_meta ) {
				if ( $key === $first_uuid_key ) {
					continue; // Skip the first UUID.
				}

				// Delete all UUIDs except the first one.
				$object->delete_meta_data_by_mid( $uuid_meta->id );
			}

			// Rebuild $uuid_values from updated $uuids.
			$uuid_values = array( reset( $uuids )->value );
		}

		// Check conditions for updating the UUID.
		$should_update_uuid = empty( $uuid_values )
			|| ( isset( $uuid_values[0] ) && ! Uuid::isValid( $uuid_values[0] ) )
			|| ( isset( $uuid_values[0] ) && $this->uuid_postmeta_exists( $uuid_values[0], $object ) );

		if ( $should_update_uuid ) {
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

		// Check if there's more than one uuid, if so, keep the first and delete the rest.
		if ( count( $uuids ) > 1 ) {
			// Keep the first UUID and remove the rest.
			for ( $i = 1; $i < count( $uuids ); $i++ ) {
				delete_user_meta( $user->ID, '_woocommerce_pos_uuid', $uuids[ $i ] );
			}
			$uuids = array( $uuids[0] );
		}

		// Check conditions for updating the UUID.
		$should_update_uuid = empty( $uuids )
			|| ( isset( $uuids[0] ) && ! Uuid::isValid( $uuids[0] ) )
			|| ( isset( $uuids[0] ) && $this->uuid_usermeta_exists( $uuids[0], $user->ID ) );

		if ( $should_update_uuid ) {
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
	private function get_term_uuid( $term ): string {
		$uuids = get_term_meta( $term->term_id, '_woocommerce_pos_uuid', false );

		// Check if there's more than one uuid, if so, keep the first and delete the rest.
		if ( count( $uuids ) > 1 ) {
			// Keep the first UUID and remove the rest.
			for ( $i = 1; $i < count( $uuids ); $i++ ) {
				delete_term_meta( $term->term_id, '_woocommerce_pos_uuid', $uuids[ $i ] );
			}
			$uuids = array( $uuids[0] );
		}

		// Check conditions for updating the UUID.
		$should_update_uuid = empty( $uuids )
			|| ( isset( $uuids[0] ) && ! Uuid::isValid( $uuids[0] ) )
			|| ( isset( $uuids[0] ) && $this->uuid_termmeta_exists( $uuids[0], $term->term_id ) );

		if ( $should_update_uuid ) {
			$uuid = $this->create_uuid();
			add_term_meta( $term->term_id, '_woocommerce_pos_uuid', $uuid, true );
			return $uuid;
		}

		return $uuids[0];
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

	/**
	 * Check if the given UUID is unique.
	 *
	 * @param string  $uuid The UUID to check.
	 * @param WC_Data $object The WooCommerce data object.
	 * @return bool True if unique, false otherwise.
	 */
	private function uuid_postmeta_exists( string $uuid, WC_Data $object ): bool {
		global $wpdb;

		if ( $object instanceof WC_Abstract_Order && class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// Check the orders meta table.
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s AND order_id != %d LIMIT 1",
					$uuid,
					$object->get_id()
				)
			);
		} else {
			// Check the postmeta table.
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s AND post_id != %d LIMIT 1",
					$uuid,
					$object->get_id()
				)
			);
		}

		// Convert the result to a boolean.
		return (bool) $result;
	}

	/**
	 *
	 */
	private function get_order_ids_by_uuid( string $uuid ) {
		global $wpdb;

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// Check the orders meta table.
			$result = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = %s",
					$uuid
				)
			);
		} else {
			// Check the postmeta table.
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
	 * @param string $uuid The UUID to check.
	 * @param int    $exclude_id The user ID to exclude from the check.
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

		// Convert the result to a boolean.
		return (bool) $result;
	}

	/**
	 * Check if the given UUID already exists for any term.
	 *
	 * @param string $uuid The UUID to check.
	 * @param int    $exclude_term_id The term ID to exclude from the check.
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

		// Convert the result to a boolean.
		return (bool) $result;
	}
}
