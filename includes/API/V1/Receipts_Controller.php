<?php
/**
 * Receipts REST controller.
 *
 * @package WCPOS\WooCommercePOS\API\V1
 */

namespace WCPOS\WooCommercePOS\API\V1;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Print_Counter;
use WCPOS\WooCommercePOS\Services\Print_Counter_Busy_Exception;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Receipt_Service;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Template_Pdf_Service;
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
	 * Declare routes with special permission-gate handling.
	 *
	 * @return array<string, string[]> Route classifications.
	 */
	public function wcpos_route_classifications(): array {
		return array(
			'permission_error_passthrough' => array( "/{$this->namespace}/{$this->rest_base}/" ),
		);
	}

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
					'intent' => array(
						'type' => 'string',
						'enum' => array( 'print' ),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'document' => array(
						'type'              => 'string',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'mode'     => array(
						'type'              => 'string',
						'required'          => false,
						'validate_callback' => static function ( $value, $request ): bool {
							return null !== $request->get_param( 'document' ) || in_array( $value, array( 'fiscal', 'live' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>[\\d]+)/pdf',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pdf' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'intent' => array(
						'type' => 'string',
						'enum' => array( 'print' ),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'document' => array(
						'type'              => 'string',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order_id'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'mode' => array(
						'type' => 'string',
						'validate_callback' => static function ( $value, $request ): bool {
							return null !== $request->get_param( 'document' ) || in_array( $value, array( 'fiscal', 'live' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default' => 'live',
					),
					'template_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>[\\d]+)/print',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'print_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args' => array(
					'order_id' => array(
						'type' => 'integer',
						'required' => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'absint',
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
				/* translators: REST API schema field label or error message. */
				__( 'Invalid order.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$document = $this->get_document_payload( $request );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$snapshot_store = Receipt_Snapshot_Store::instance();
		$requested_mode = null !== $document ? 'fiscal' : $request->get_param( 'mode' );
		if ( null !== $requested_mode && ! \in_array( $requested_mode, array( 'fiscal', 'live' ), true ) ) {
			return new WP_Error(
				'wcpos_receipt_invalid_mode',
				/* translators: REST API schema field label or error message. */
				__( 'Invalid receipt mode.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$mode    = $snapshot_store->resolve_mode( $requested_mode );
		$payload        = null;

		if ( 'fiscal' === $mode ) {
			$payload = null !== $document ? $document : $snapshot_store->get_snapshot( $order_id );
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

		if ( 'print' === $request->get_param( 'intent' ) ) {
			try {
				$counter = new Receipt_Print_Counter();
				$payload = $counter->mark( $payload, $counter->count( $order ), $order );
			} catch ( Print_Counter_Busy_Exception $e ) {
				return $this->counter_busy();
			}
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
	 * Record a print performed by the caller.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error Print count and copy marking.
	 */
	public function print_item( $request ) {
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order ) {
			return new WP_Error( 'wcpos_receipt_invalid_order', __( 'Invalid order.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$counter = new Receipt_Print_Counter();
		try {
			$count = $counter->count( $order );
		} catch ( Print_Counter_Busy_Exception $e ) {
			return $this->counter_busy();
		}

		return array_merge( array( 'print_count' => $count ), $counter->mark( array(), $count )['fiscal'] );
	}

	/**
	 * Get receipt PDF bytes for an order.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return Raw_Response|WP_Error
	 */
	public function get_pdf( $request ) {
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order ) {
			return new WP_Error(
				'wcpos_receipt_order_not_found',
				__( 'Order not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$template_id = trim( (string) $request->get_param( 'template_id' ) );
		if ( '' === $template_id ) {
			return new WP_Error(
				'wcpos_receipt_missing_template',
				__( 'A template_id is required.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$template = Print_Job_Service::load_template( $template_id );
		if ( null === $template ) {
			return new WP_Error(
				'wcpos_receipt_template_not_found',
				__( 'Template not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$document = $this->get_document_payload( $request );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$service = new Template_Pdf_Service();
		// A native (WP Overnight) document cannot carry the copy marking, so a print
		// of one is not counted: the audit count only advances for documents that show it.
		$counting = 'print' === $request->get_param( 'intent' ) && ! $service->is_native( $template );
		try {
			$data = $document;
			if ( null === $data ) {
				$receipt_request = clone $request;
				$receipt_request->set_param( 'mode', $request->get_param( 'mode' ) ?? 'live' );
				$receipt_request->set_param( 'intent', null );
				$receipt = $this->get_item( $receipt_request );
				if ( is_wp_error( $receipt ) ) {
					return $receipt;
				}
				$data = $receipt['data'];
			}
			if ( $counting ) {
				// The count is reserved under the order lock for the whole render and
				// committed only once a PDF exists.
				$counter = new Receipt_Print_Counter();
				$pdf     = $counter->count_after(
					$order,
					static function ( int $count ) use ( $counter, $service, $template, $order, $data ): string {
						return $service->render( $template, $order, $counter->mark( $data, $count, $order ) );
					}
				);
			} else {
				$pdf = $service->render( $template, $order, $data );
			}
		} catch ( Print_Counter_Busy_Exception $e ) {
			return $this->counter_busy();
		} catch ( \Throwable $e ) {
			Logger::log( sprintf( 'Receipt PDF render failed for order %d: %s', $order->get_id(), $e->getMessage() ) );

			return new WP_Error(
				'wcpos_receipt_pdf_failed',
				__( 'Could not generate the receipt PDF.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		if ( '' === $pdf ) {
			return new WP_Error(
				'wcpos_receipt_pdf_empty',
				__( 'Could not generate the receipt PDF.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}
		return Raw_Response::serve(
			$pdf,
			'application/pdf',
			array(
				'Content-Disposition' => sprintf( 'attachment; filename="receipt-%d.pdf"', $order->get_id() ),
				'Content-Length'      => (string) \strlen( $pdf ),
				'Cache-Control'       => 'no-store',
			)
		);
	}

	/** Another print of this order holds the counter; the caller retries. */
	private function counter_busy(): WP_Error {
		return new WP_Error( 'wcpos_receipt_print_busy', __( 'Another print of this order is in progress; try again.', 'woocommerce-pos' ), array( 'status' => 503 ) );
	}

	/**
	 * Resolve only a frozen refund belonging to the requested order.
	 *
	 * @param WP_REST_Request $request Receipt request.
	 * @return array|WP_Error|null
	 */
	private function get_document_payload( WP_REST_Request $request ) {
		$document = $request->get_param( 'document' );
		if ( null === $document ) {
			return null;
		}
		if ( ! is_string( $document ) || ! preg_match( '/\Arefund:([1-9][0-9]*)\z/', $document, $matches ) ) {
			return new WP_Error( 'wcpos_receipt_invalid_document', __( 'Invalid receipt document.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$record = ( new Fiscal_Record_Store() )->find_refund( (int) $request['order_id'], (int) $matches[1] );
		return $record ? $record['payload'] : new WP_Error( 'wcpos_receipt_document_missing', __( 'Receipt document not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
	}

	/**
	 * Permissions check.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_insufficient_permissions',
				__( 'Sorry, you cannot view receipts.', 'woocommerce-pos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
