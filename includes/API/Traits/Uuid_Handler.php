<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Data;
use WC_Order_Item;
use WCPOS\WooCommercePOS\Logger;
use WP_User;
use function array_count_values;
use function delete_post_meta;
use function delete_user_meta;
use function get_post_meta;
use function get_user_meta;
use function in_array;
use function update_user_meta;

trait Uuid_Handler {

    private $uuids;

    /**
     * Note: this gets all postmeta uuids, including products, orders, we're just interested in doing a sanity check
     * This addresses a bug where I have seen two products with the same uuid
     *
     * @return array
     */
    private function get_all_postmeta_uuids(): array {
        global $wpdb;
        $result = $wpdb->get_col(
            "
            SELECT meta_value
            FROM $wpdb->postmeta
            WHERE meta_key = '_woocommerce_pos_uuid'
            "
        );
        return $result;
    }

    /**
     * @return array
     */
    private function get_all_usermeta_uuids(): array {
        global $wpdb;
        $result = $wpdb->get_col(
            "
            SELECT meta_value
            FROM $wpdb->usermeta
            WHERE meta_key = '_woocommerce_pos_uuid'
            "
        );
        return $result;
    }

    /**
     * Make sure the product has a uuid
     */
    private function maybe_add_post_uuid( WC_Data $object ) {
        $uuids = get_post_meta( $object->get_id(), '_woocommerce_pos_uuid', false );
        $uuid_counts = array_count_values( $this->uuids );

        // if there is no uuid, add one, ie: new product
        if ( empty( $uuids ) || empty( $uuids[0] ) ) {
            $object->update_meta_data( '_woocommerce_pos_uuid', $this->create_uuid() );
        }

        // this is a sanity check, if there is more than one uuid for a product, delete them all and add a new one
        if ( count( $uuids ) > 1 || count( $uuids ) === 1 && $uuid_counts[ $uuids[0] ] > 1 ) {
            delete_post_meta( $object->get_id(), '_woocommerce_pos_uuid' );
            $object->update_meta_data( '_woocommerce_pos_uuid', $this->create_uuid() );
        }
    }

    /**
     * @param WP_User $user
     * @return void
     */
    private function maybe_add_user_uuid( WP_User $user ) {
        $uuids = get_user_meta( $user->ID, '_woocommerce_pos_uuid', false );
        $uuid_counts = array_count_values( $this->uuids );

        if ( empty( $uuids ) || empty( $uuids[0] ) ) {
            update_user_meta( $user->ID, '_woocommerce_pos_uuid', $this->create_uuid() );
        }

        // this is a sanity check, if there is more than one uuid for a product, delete them all and add a new one
        if ( count( $uuids ) > 1 || count( $uuids ) === 1 && $uuid_counts[ $uuids[0] ] > 1 ) {
            delete_user_meta( $user->ID, '_woocommerce_pos_uuid' );
            update_user_meta( $user->ID, '_woocommerce_pos_uuid', $this->create_uuid() );
        }
    }

    /**
     * @TODO: this is called from the order class, so it doesn't have a sanity check yet
     *
     * @param WC_Order_Item $item
     * @return void
     */
    private function maybe_add_order_item_uuid( WC_Order_Item $item ) {
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
            $uuid = Uuid::uuid4()->toString();
            while ( in_array( $uuid, $this->uuids ) ) { // ensure the new UUID is unique
                Logger::log( 'This should not happen!!' );
                $uuid = Uuid::uuid4()->toString();
            }
            $this->uuids[] = $uuid; // update the UUID list
            return $uuid;
        } catch ( Exception $e ) {
            // Log the error message
            Logger::log( 'UUID generation failed: ' . $e->getMessage() );

            // Return a fallback value
            return 'fallback-uuid-' . time();
        }
    }

}
