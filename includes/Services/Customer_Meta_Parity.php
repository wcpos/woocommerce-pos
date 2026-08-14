<?php
/**
 * POS customer response parity (v1 → v2).
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use Exception;
use WC_Customer;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Response;
use WP_User;

/**
 * Restores the v1 customer response contract on POS-marked wc/v3 customer
 * serializations (#1309, ruling 2026-07-29: v1.9 parity).
 *
 * Stock wc/v3 withholds the entire `meta_data` key from non-administrators
 * (`wc_current_user_has_role( 'administrator' )` in
 * WC_REST_Customers_V2_Controller::get_formatted_item_data_core), and never
 * serves a top-level `tax_ids` field. V1\Customers_Controller::wcpos_customer_response()
 * re-added non-protected meta for cashiers and built `tax_ids` via
 * Tax_Id_Reader; the v2 catalog proxy and push echo forward wc/v3's
 * serialization untouched, so both fields silently vanished for POS clients.
 *
 * Hooking `woocommerce_rest_prepare_customer` gated on wcpos_request() covers
 * every v2 surface at once — proxy list/targeted pulls AND the /push/customers
 * write echo — while leaving non-POS wc/v3 traffic exactly stock. Priority 20
 * runs after v1's own response filter on v1 dispatches; both compute the same
 * result from the datastore, so double-processing is idempotent.
 *
 * The `_woocommerce_pos_uuid` entry is NOT this class's concern: it is
 * protected meta, filtered out here just as it was in v1's re-add, and served
 * by Proxy_Uuid_Stamper (proxy) / the write-path stamper instead.
 */
class Customer_Meta_Parity {
	/**
	 * Register the customer response filter.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_prepare_customer', array( $this, 'add_pos_customer_fields' ), 20, 3 );
	}

	/**
	 * Mirror v1's customer response shape onto POS-marked serializations:
	 * non-protected meta_data for every POS user, plus top-level tax_ids.
	 *
	 * @param WP_REST_Response $response  The response object.
	 * @param mixed            $user_data User object used to create the response.
	 * @param mixed            $request   Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function add_pos_customer_fields( $response, $user_data, $request ) {
		if ( ! \wcpos_request() || ! $response instanceof WP_REST_Response || ! $user_data instanceof WP_User ) {
			return $response;
		}

		$data = $response->get_data();

		try {
			$customer = new WC_Customer( $user_data->ID );

			$filtered_meta_data = array_filter(
				$customer->get_meta_data(),
				static function ( $meta ) {
					return ! is_protected_meta( $meta->key, 'user' );
				}
			);

			// Convert to the WC REST API expected format.
			$data['meta_data'] = array_map(
				static function ( $meta ) {
					return array(
						'id'    => $meta->id,
						'key'   => $meta->key,
						'value' => $meta->value,
					);
				},
				array_values( $filtered_meta_data )
			);
		} catch ( Exception $e ) {
			Logger::log( 'Error getting customer meta data: ' . $e->getMessage() );
		}

		// Structured tax_ids list (read fallback across legacy plugin meta keys).
		$data['tax_ids'] = ( new Tax_Id_Reader() )->read_for_user( $user_data->ID );

		$response->set_data( $data );

		return $response;
	}
}
