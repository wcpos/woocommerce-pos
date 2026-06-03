<?php
/**
 * Print Jobs REST controller.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Diagnostic;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Trigger_Service;
use WCPOS\WooCommercePOS\Services\PrintNode_Client;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WCPOS\WooCommercePOS\Services\Provider;
use WCPOS\WooCommercePOS\Services\Star_Online_Client;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
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
	 * Cloud printer registry.
	 *
	 * @var Cloud_Print_Registry
	 */
	protected $registry;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->jobs     = new Print_Job_Service();
		$this->registry = new Cloud_Print_Registry();
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
			'/' . $this->rest_base . '/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_print' ),
				'permission_callback' => array( $this, 'manage_permissions_check' ),
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

		register_rest_route(
			$this->namespace,
			'/printnode/printers',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'printnode_printers' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/star-online/devices',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'star_online_devices' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Proxy the PrintNode account's printer list for the add-printer wizard.
	 *
	 * The API key is supplied in the POST body (never the URL/query, so it does
	 * not leak through logs or history) and is used only for this request; it is
	 * never returned. Only id/name/state are surfaced to the client.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function printnode_printers( $request ) {
		// The API key is a secret: read it from the request body only, never the
		// query string, so it can't leak through server logs or browser history.
		// get_param() merges query + body, so it is deliberately avoided here.
		$query = $request->get_query_params();
		if ( isset( $query['api_key'] ) ) {
			return new WP_Error(
				'wcpos_printnode_api_key_in_query',
				__( 'The PrintNode API key must be sent in the request body, not the query string.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		// JSON bodies land in the JSON param set, form-encoded bodies in POST;
		// read both (cast handles the null-on-absent case) and never the query set.
		$json    = (array) $request->get_json_params();
		$body    = (array) $request->get_body_params();
		$api_key = (string) ( $json['api_key'] ?? $body['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new WP_Error(
				'wcpos_printnode_missing_api_key',
				__( 'A PrintNode API key is required.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new PrintNode_Client( $api_key ) )->printers();
		if ( is_wp_error( $result ) ) {
			// A rejected key is a client input error (the value just typed into
			// the wizard) → 400 so the UI can prompt for a correct key. Any other
			// PrintNode failure is an upstream/transport error → 502 (matching
			// test_print_printnode()).
			$status = 'wcpos_printnode_unauthorized' === $result->get_error_code() ? 400 : 502;

			return new WP_Error(
				'wcpos_printnode_printers_failed',
				$result->get_error_message(),
				array( 'status' => $status )
			);
		}

		$printers = array();
		foreach ( (array) $result as $printer ) {
			if ( ! is_array( $printer ) || ! isset( $printer['id'] ) ) {
				continue;
			}
			$printers[] = array(
				'id'    => (int) $printer['id'],
				'name'  => (string) ( $printer['name'] ?? '' ),
				'state' => (string) ( $printer['state'] ?? '' ),
			);
		}

		return new WP_REST_Response( array( 'printers' => $printers ), 200 );
	}

	/**
	 * Proxy the stario.online device list for the add-printer wizard.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function star_online_devices( $request ) {
		$query = $request->get_query_params();
		if ( isset( $query['api_key'] ) ) {
			return new WP_Error(
				'wcpos_star_online_api_key_in_query',
				__( 'The Star Online API key must be sent in the request body, not the query string.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$json    = (array) $request->get_json_params();
		$body    = (array) $request->get_body_params();
		$api_key = (string) ( $json['api_key'] ?? $body['api_key'] ?? '' );
		$url     = (string) ( $json['cloudprnt_url'] ?? $body['cloudprnt_url'] ?? '' );

		$api_base = Star_Online_Client::api_base_from_cloudprnt_url( $url );
		$group    = Star_Online_Client::group_from_cloudprnt_url( $url );
		if ( '' === $api_key || null === $api_base || '' === $group ) {
			return new WP_Error(
				'wcpos_star_online_invalid_request',
				__( 'A Star Online API key and a valid stario.online CloudPRNT URL are required.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new Star_Online_Client( $api_base, $api_key ) )->devices( $group );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$devices = array();
		foreach ( $result as $device ) {
			if ( ! \is_array( $device ) || empty( $device['AccessIdentifier'] ) ) {
				continue;
			}
			$state  = 'unknown';
			$status = isset( $device['Status'] ) && \is_array( $device['Status'] ) ? $device['Status'] : array();
			if ( array_key_exists( 'Online', $status ) ) {
				$state = $status['Online'] ? 'online' : 'offline';
			}
			$devices[] = array(
				'id'    => (string) $device['AccessIdentifier'],
				'name'  => (string) ( $device['ClientType'] ?? $device['AccessIdentifier'] ),
				'state' => $state,
			);
		}

		return new WP_REST_Response( array( 'devices' => $devices ), 200 );
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
		$this->registry->record_seen( $printer_id );
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

		if ( ! $this->registry->verify_token( $printer_id, $token ) ) {
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
		return Raw_Response::serve( $body, $content_type );
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
		$this->registry->record_seen( $printer_id );
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

		$payload     = (string) $request->get_param( 'payload' );
		$format      = (string) $request->get_param( 'format' );
		$template_id = sanitize_text_field( (string) $request->get_param( 'template_id' ) );
		$order_id    = (int) $request->get_param( 'order_id' );

		$printer    = $this->registry->get_printer( $printer_id );
		$validation = $this->validate_job_for_printer( $printer, $payload, $format );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$provider = null !== $printer ? (string) ( $printer['provider'] ?? '' ) : '';

		// PrintNode never polls, so a raw payload could never be delivered — a
		// PrintNode job must be order-based (rendered + submitted out-of-band).
		if ( 'printnode' === $provider && ( 0 === $order_id || '' === $template_id ) ) {
			return new WP_Error(
				'wcpos_print_job_printnode_requires_template',
				__( 'PrintNode print jobs require an order and a template.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		// Order-based job: render server-side from the order + template, deriving
		// the wire format from the printer's provider (shared with the auto-print
		// trigger). Star/Epson are fetched on poll; PrintNode is submitted.
		if ( 0 !== $order_id && '' !== $template_id ) {
			if ( null === $printer ) {
				// Without a known printer there is no provider to render for, and
				// the job could never be polled/submitted — fail loudly rather
				// than enqueue a job that silently never prints.
				return new WP_Error(
					'wcpos_print_job_unknown_printer',
					__( 'Unknown printer.', 'woocommerce-pos' ),
					array( 'status' => 404 )
				);
			}

			return $this->create_order_job( $printer_id, $printer, $order_id, $template_id );
		}

		$id = $this->jobs->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => (string) $request->get_param( 'content_type' ),
				'payload'      => $payload,
				'order_id'     => $order_id,
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
	 * Enqueue an order-based job, deriving the wire format from the printer's
	 * provider via the shared trigger-service helper.
	 *
	 * @param string $printer_id  Registered printer id.
	 * @param array  $printer     Registered printer config.
	 * @param int    $order_id    Order id to render.
	 * @param string $template_id Template id (numeric) or virtual slug.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	private function create_order_job( string $printer_id, array $printer, int $order_id, string $template_id ) {
		if ( ! wc_get_order( $order_id ) ) {
			// Surface the bad order up front rather than enqueue a job that
			// render_payload() can only ever resolve to an empty (never-printing) payload.
			return new WP_Error(
				'wcpos_print_job_unknown_order',
				__( 'Unknown order.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$template = Print_Job_Service::load_template( $template_id );
		if ( null === $template ) {
			return new WP_Error(
				'wcpos_print_job_unknown_template',
				__( 'Unknown template.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$id = Cloud_Print_Trigger_Service::enqueue_order_job(
			$this->jobs,
			$printer_id,
			$printer,
			$order_id,
			$template_id,
			$template
		);
		if ( $id <= 0 ) {
			return new WP_Error(
				'wcpos_print_job_template_not_printable',
				__( 'The selected template cannot be printed on this printer.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$response = rest_ensure_response( $this->jobs->get( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Enqueue a diagnostic test print for a registered printer.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function test_print( $request ) {
		$printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );
		$printer    = $this->registry->get_printer( $printer_id );
		if ( null === $printer ) {
			return new WP_Error(
				'wcpos_print_job_unknown_printer',
				__( 'Unknown printer.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$provider = (string) ( $printer['provider'] ?? '' );

		if ( 'printnode' === $provider ) {
			return $this->test_print_printnode( $printer );
		}

		if ( 'star-online' === $provider ) {
			return $this->test_print_star_online( $printer_id, $printer );
		}

		try {
			$diag = ( new Cloud_Print_Diagnostic() )->build( (string) $printer['provider'], (string) $printer['name'] );
		} catch ( \RuntimeException $e ) {
			return new WP_Error(
				'wcpos_print_job_no_diagnostic',
				__( 'Test print is not available for this printer yet.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$id = $this->jobs->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => $diag['content_type'],
				'payload'      => $diag['payload'],
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
	 * Queue a Star Markup test receipt and submit it through the push pipeline.
	 *
	 * @param string $printer_id Registered printer id.
	 * @param array  $printer    Registered star-online printer.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	private function test_print_star_online( string $printer_id, array $printer ) {
		$date    = gmdate( 'Y-m-d H:i' );
		$markup  = '[align: middle][bold: on]WCPOS[bold: off]' . "\n";
		$markup .= 'Cloud Print Test' . "\n" . '[align: left]';
		$markup .= 'Printer: ' . $this->star_escape( (string) $printer['name'] ) . "\n";
		$markup .= 'Date: ' . $date . "\n";
		$markup .= 'If you can read this, printing works!' . "\n";
		$markup .= '[feed][cut]';

		$id = $this->jobs->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => 'text/vnd.star.markup',
				'payload'      => base64_encode( $markup ),
			)
		);
		if ( $id <= 0 ) {
			return new WP_Error(
				'wcpos_print_job_create_failed',
				__( 'Print job could not be created.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		wp_schedule_single_event( time(), Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $id ) );
		( new \WCPOS\WooCommercePOS\Services\Cloud_Print_Submit_Service() )->submit( $id );

		$response = rest_ensure_response( $this->jobs->get( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Escape brackets for Star Document Markup text.
	 *
	 * @param string $value Text.
	 *
	 * @return string
	 */
	private function star_escape( string $value ): string {
		return str_replace( array( '[', ']' ), array( '[[', ']]' ), $value );
	}

	/**
	 * Submit a diagnostic PDF to a PrintNode printer.
	 *
	 * @param array $printer Registered PrintNode printer.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	private function test_print_printnode( array $printer ) {
		$api_key       = (string) ( $printer['printnode_api_key'] ?? '' );
		$pn_printer_id = (int) ( $printer['printnode_printer_id'] ?? 0 );
		if ( '' === $api_key || 0 === $pn_printer_id ) {
			return new WP_Error(
				'wcpos_print_job_printnode_unconfigured',
				__( 'This PrintNode printer is missing its API key or printer id.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		try {
			$pdf = ( new Cloud_Print_Diagnostic() )->build_pdf( (string) $printer['name'] );
		} catch ( \Throwable $e ) {
			// Defense in depth: a Dompdf/font-cache/temp-dir failure must not
			// surface as an uncaught 500. Mirror the render_payload() guard.
			Logger::log( 'Cloud print: PrintNode diagnostic PDF render failed: ' . $e->getMessage() );

			return new WP_Error(
				'wcpos_print_job_diagnostic_failed',
				__( 'Could not generate the test print.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		$result = ( new PrintNode_Client( $api_key ) )->submit_job(
			$pn_printer_id,
			'WCPOS Test Print',
			'pdf_base64',
			base64_encode( $pdf )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wcpos_print_job_printnode_failed',
				$result->get_error_message(),
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'submitted'         => true,
				'external_provider' => 'printnode',
				'external_job_id'   => (string) $result['id'],
				'external_state'    => 'submitted',
			),
			201
		);
	}

	/**
	 * Validate a job against the target printer's provider.
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
		$provider = $printer['provider'] ?? 'star-cloudprnt';

		if ( 'epos-xml' === Provider::wire_format( $provider, 'thermal' ) ) {
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
