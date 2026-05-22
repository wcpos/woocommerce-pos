<?php
/**
 * Print Jobs REST controller.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Print_Jobs_Controller class.
 */
class Print_Jobs_Controller extends WP_REST_Controller {
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
	protected $rest_base = 'print-jobs';

	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	protected $jobs;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->jobs = new Print_Job_Service();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Enqueue a print job (raw payload or order-based).
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );
		if ( '' === $printer_id ) {
			return new WP_Error(
				'wcpos_print_job_missing_printer',
				__( 'A printer_id is required.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$payload = (string) $request->get_param( 'payload' );
		$format  = (string) $request->get_param( 'format' );

		$registry   = new Cloud_Print_Registry();
		$validation = $this->validate_job_for_printer( $registry->get_printer( $printer_id ), $payload, $format );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$id = $this->jobs->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => (string) $request->get_param( 'content_type' ),
				'payload'      => $payload,
				'order_id'     => $request->get_param( 'order_id' ),
				'format'       => $format,
			)
		);

		$response = rest_ensure_response( $this->jobs->get( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Validate a job against the target printer's protocol.
	 *
	 * @param array|null $printer Registered printer, or null when unknown.
	 * @param string     $payload Base64 payload (raw jobs).
	 * @param string     $format  Render format (order-based jobs).
	 *
	 * @return true|WP_Error
	 */
	private function validate_job_for_printer( ?array $printer, string $payload, string $format ) {
		if ( null === $printer ) {
			return true;
		}
		$protocol = $printer['protocol'] ?? 'star-cloudprnt';

		if ( 'epson-sdp' === $protocol ) {
			if ( '' !== $payload ) {
				return new WP_Error(
					'wcpos_print_job_incompatible',
					__( 'Epson Server Direct Print accepts order-based ePOS-Print jobs only, not raw payloads.', 'woocommerce-pos' ),
					array( 'status' => 400 )
				);
			}
			if ( '' !== $format && 'epos-xml' !== $format ) {
				return new WP_Error(
					'wcpos_print_job_incompatible',
					__( 'Epson Server Direct Print requires the epos-xml format.', 'woocommerce-pos' ),
					array( 'status' => 400 )
				);
			}

			return true;
		}

		if ( 'epos-xml' === $format ) {
			return new WP_Error(
				'wcpos_print_job_incompatible',
				__( 'Star CloudPRNT does not accept the epos-xml format.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Permission check for app/admin management routes.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function manage_permissions_check( $request ) {
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_insufficient_permissions',
				__( 'Sorry, you cannot manage print jobs.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
