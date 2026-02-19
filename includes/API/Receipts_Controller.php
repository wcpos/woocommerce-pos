<?php
/**
 * Receipts REST controller.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Receipt_Service;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Receipts_Controller class.
 */
class Receipts_Controller extends WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'receipts';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>[\\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'order_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'mode'     => array(
						'type'              => 'string',
						'required'          => false,
						'enum'              => array( 'fiscal', 'live' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Get receipt payload for an order.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array|WP_Error
	 */
	public function get_item( $request ) {
		$order_id = (int) $request->get_param( 'order_id' );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'wcpos_receipt_invalid_order',
				__( 'Invalid order.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$snapshot_store = Receipt_Snapshot_Store::instance();
		$requested_mode = $request->get_param( 'mode' );
		if ( null !== $requested_mode && ! \in_array( $requested_mode, array( 'fiscal', 'live' ), true ) ) {
			return new WP_Error(
				'wcpos_receipt_invalid_mode',
				__( 'Invalid receipt mode.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$mode    = $snapshot_store->resolve_mode( $requested_mode );
		$payload        = null;

		if ( 'fiscal' === $mode ) {
			$payload = $snapshot_store->get_snapshot( $order_id );
			if ( ! $payload ) {
				return new WP_Error(
					'wcpos_receipt_snapshot_missing',
					__( 'No fiscal snapshot found for this order.', 'woocommerce-pos' ),
					array( 'status' => 404 )
				);
			}
		} else {
			$payload = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		}

		return array(
			'order_id'     => $order_id,
			'mode'         => $mode,
			'has_snapshot' => $snapshot_store->has_snapshot( $order_id ),
			'submission_status' => ( new Fiscal_Receipt_Service() )->get_submission_status( $order_id ),
			'data'         => $payload,
		);
	}

	/**
	 * Permissions check.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_view',
				__( 'Sorry, you cannot view receipts.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
