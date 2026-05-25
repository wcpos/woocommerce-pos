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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reprint',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reprint_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/cloudprnt',
			array(
				array(
					'methods'             => array( 'POST', 'GET', 'DELETE' ),
					'callback'            => array( $this, 'cloudprnt' ),
					'permission_callback' => array( $this, 'printer_token_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/epson-sdp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'epson_sdp' ),
					'permission_callback' => array( $this, 'printer_token_permissions_check' ),
				),
			)
		);
	}


	/**
	 * List print jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		return rest_ensure_response(
			$this->jobs->query(
				array(
					'printer_id' => $request->get_param( 'printer_id' ),
					'status'     => $request->get_param( 'status' ),
				)
			)
		);
	}

	/**
	 * Get a print job.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$job = $this->jobs->get( (int) $request->get_param( 'id' ) );
		if ( null === $job ) {
			return new WP_Error(
				'wcpos_print_job_not_found',
				__( 'Print job not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $job );
	}

	/**
	 * Cancel a print job.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->jobs->get( $id ) ) {
			return new WP_Error(
				'wcpos_print_job_not_found',
				__( 'Print job not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}
		$this->jobs->set_status( $id, Print_Job_Service::STATUS_CANCELLED );

		return rest_ensure_response( $this->jobs->get( $id ) );
	}

	/**
	 * Reprint a print job by copying it to a new pending job.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function reprint_item( $request ) {
		$source = $this->jobs->get( (int) $request->get_param( 'id' ) );
		if ( null === $source ) {
			return new WP_Error(
				'wcpos_print_job_not_found',
				__( 'Print job not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}
		$new_id = $this->jobs->create(
			array(
				'printer_id'   => $source['printer_id'],
				'content_type' => $source['content_type'],
				'payload'      => $source['payload'],
				'order_id'     => $source['order_id'] ? $source['order_id'] : null,
				'format'       => $source['format'] ? $source['format'] : null,
			)
		);
		if ( $new_id <= 0 ) {
			return new WP_Error(
				'wcpos_print_job_create_failed',
				__( 'Print job could not be created.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		$response = rest_ensure_response( $this->jobs->get( $new_id ) );
		$response->set_status( 201 );

		return $response;
	}


	/**
	 * Star CloudPRNT poll/fetch/confirm endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function cloudprnt( $request ) {
		$printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );
		$this->jobs->release_stale_claims( $printer_id );

		if ( 'POST' === $request->get_method() ) {
			if ( $this->jobs->find_active_claim( $printer_id ) ) {
				return rest_ensure_response( array( 'jobReady' => false ) );
			}

			$job = $this->jobs->next_pending( $printer_id );
			if ( null === $job ) {
				return rest_ensure_response( array( 'jobReady' => false ) );
			}

			return rest_ensure_response(
				array(
					'jobReady'  => true,
					'jobToken'  => (string) $job['id'],
					'mediaType' => $job['content_type'] ? $job['content_type'] : 'application/octet-stream',
				)
			);
		}

		$job = $this->get_cloud_job_for_request( $request, $printer_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( 'DELETE' === $request->get_method() ) {
			$code   = (string) $request->get_param( 'code' );
			$status = '' === $code || '000' === $code ? Print_Job_Service::STATUS_PRINTED : Print_Job_Service::STATUS_FAILED;
			$this->jobs->set_status( (int) $job['id'], $status );

			return rest_ensure_response( array( 'ok' => true ) );
		}

		if ( ! $this->jobs->try_claim( (int) $job['id'] ) ) {
			return rest_ensure_response( array( 'jobReady' => false ) );
		}

		return $this->serve_raw( $this->jobs->render_payload( $job ), $job['content_type'] ? $job['content_type'] : 'application/octet-stream' );
	}

	/**
	 * Permission check for printer-token routes.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function printer_token_permissions_check( $request ) {
		$printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );
		$token      = (string) $request->get_param( 'pt' );
		$registry   = new Cloud_Print_Registry();

		if ( ! $registry->verify_token( $printer_id, $token ) ) {
			return new WP_Error(
				'wcpos_print_job_invalid_token',
				__( 'Invalid printer token.', 'woocommerce-pos' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Resolve and authorize a CloudPRNT job token.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $printer_id Printer ID.
	 *
	 * @return array|WP_Error
	 */
	private function get_cloud_job_for_request( WP_REST_Request $request, string $printer_id ) {
		$job = $this->jobs->get( (int) $request->get_param( 'token' ) );
		if ( null === $job || $printer_id !== $job['printer_id'] ) {
			return new WP_Error(
				'wcpos_print_job_not_found',
				__( 'Print job not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		return $job;
	}

	/**
	 * Serve raw bytes from a REST callback.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type Content type.
	 *
	 * @return \WP_REST_Response
	 */
	private function serve_raw( string $body, string $content_type ) {
		$response = rest_ensure_response( null );
		$response->set_status( 200 );
		$response->header( 'Content-Type', $content_type );

		$served = false;
		add_filter(
			'rest_pre_serve_request',
			static function ( $served_result, $result ) use ( $response, $body, &$served ) {
				if ( $served || $result !== $response ) {
					return $served_result;
				}
				$served = true;
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw printer bytes.

				return true;
			},
			10,
			2
		);

		return $response;
	}


	/**
	 * Epson Server Direct Print poll/result endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function epson_sdp( $request ) {
		$printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );
		$raw_body   = (string) $request->get_body();
		$soap       = 'text/xml; charset=utf-8';
		$ack        = '<response success="true" code="" status=""/>';

		$this->jobs->release_stale_claims( $printer_id );

		if ( false !== strpos( $raw_body, 'success=' ) ) {
			$claim = $this->jobs->find_active_claim( $printer_id );
			if ( null !== $claim ) {
				$ok = false !== strpos( $raw_body, 'success="true"' );
				$this->jobs->set_status( (int) $claim['id'], $ok ? Print_Job_Service::STATUS_PRINTED : Print_Job_Service::STATUS_FAILED );
			}

			return $this->serve_raw( $ack, $soap );
		}

		if ( null !== $this->jobs->find_active_claim( $printer_id ) ) {
			return $this->serve_raw( $ack, $soap );
		}

		$job = $this->jobs->next_pending( $printer_id );
		if ( null === $job ) {
			return $this->serve_raw( $ack, $soap );
		}

		if ( ! $this->jobs->try_claim( (int) $job['id'] ) ) {
			return $this->serve_raw( $ack, $soap );
		}
		$epos = $this->jobs->render_payload( $job );

		$envelope  = '<?xml version="1.0" encoding="utf-8"?>';
		$envelope .= '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>';
		$envelope .= $epos;
		$envelope .= '</s:Body></s:Envelope>';

		return $this->serve_raw( $envelope, $soap );
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
		if ( $id <= 0 ) {
			return new WP_Error(
				'wcpos_print_job_create_failed',
				__( 'Print job could not be created.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

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
