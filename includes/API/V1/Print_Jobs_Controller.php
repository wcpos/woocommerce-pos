<?php
/**
 * Print Jobs REST controller.
 *
 * @package WCPOS\WooCommercePOS\API\V1
 */

namespace WCPOS\WooCommercePOS\API\V1;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Diagnostic;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Media_Types;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Poll_Request;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Relay_Service;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Trigger_Service;
use WCPOS\WooCommercePOS\Services\PrintNode_Client;
use WCPOS\WooCommercePOS\Services\Print_Format_Resolver;
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
	 * Declare routes with special permission-gate handling.
	 *
	 * @return array<string, string[]> Route classifications.
	 */
	public function wcpos_route_classifications(): array {
		return array(
			'public'        => array(
				"/{$this->namespace}/{$this->rest_base}/relay-verification",
			),
			'printer_token' => array(
				"/{$this->namespace}/{$this->rest_base}/cloudprnt",
				"/{$this->namespace}/{$this->rest_base}/epson-sdp",
			),
		);
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
			'/' . $this->rest_base . '/queue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_queue' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/queue/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_queue' ),
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
			'/' . $this->rest_base . '/relay-verification',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'relay_verification' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/relay/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'relay_register' ),
				'permission_callback' => array( $this, 'relay_manage_permissions_check' ),
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

		// Path-credential form: Star printers URL-encode the configured query
		// string on the wire (& becomes %26), so printer_id/pt can never
		// arrive as query parameters — but the path is transmitted verbatim.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/cloudprnt/(?P<printer_id>[^/]+)/(?P<pt>[^/]+)',
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
			'/' . $this->rest_base . '/epson-sdp/(?P<printer_id>[^/]+)/(?P<pt>[^/]+)',
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
		$id  = (int) $request->get_param( 'id' );
		$job = $this->jobs->get( $id );
		if ( null === $job ) {
			return new WP_Error(
				'wcpos_print_job_not_found',
				__( 'Print job not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->jobs->cancel_if_waiting( $id ) ) {
			return new WP_Error(
				'wcpos_print_job_not_cancellable',
				__( 'Only pending or claimed print jobs can be cancelled.', 'woocommerce-pos' ),
				array( 'status' => 409 )
			);
		}

		return rest_ensure_response( $this->jobs->get( $id ) );
	}

	/**
	 * The admin queue view: paginated jobs (payloads stripped), status counts,
	 * and per-printer backlog with last-seen data for staleness banners.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_queue( $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = min( 100, max( 1, 0 === $per_page ? 20 : $per_page ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$status          = $request->get_param( 'status' );
		$exclude_retried = 'active' === $status;
		if ( 'active' === $status ) {
			// The default queue view: everything not yet terminal-successful.
			$status = array(
				Print_Job_Service::STATUS_PENDING,
				Print_Job_Service::STATUS_CLAIMED,
				Print_Job_Service::STATUS_FAILED,
			);
		}
		$filters = array(
			'printer_id'     => $request->get_param( 'printer_id' ),
			'status'         => $status,
			'exclude_retried' => $exclude_retried,
		);

		$jobs = array_map(
			function ( array $job ): array {
				$order = $job['order_id'] ? wc_get_order( $job['order_id'] ) : false;
				if ( $order ) {
					$job['order_number']   = (string) $order->get_order_number();
					$job['order_edit_url'] = $order->get_edit_order_url();
				}

				return $job;
			},
			$this->jobs->query_rows(
				array_merge(
					$filters,
					array(
						'limit' => $per_page,
						'page'  => $page,
					)
				)
			)
		);

		// One grouped query covers all status counts and every printer's
		// backlog — the view refreshes every 30 s, so summary cost must not
		// scale with printer count.
		$summary = $this->jobs->status_summary();

		$counts = array();
		foreach ( array(
			Print_Job_Service::STATUS_PENDING,
			Print_Job_Service::STATUS_CLAIMED,
			Print_Job_Service::STATUS_PRINTED,
			Print_Job_Service::STATUS_FAILED,
			Print_Job_Service::STATUS_CANCELLED,
		) as $status ) {
			$counts[ $status ] = 0;
			foreach ( $summary as $per_status ) {
				$counts[ $status ] += isset( $per_status[ $status ] ) ? $per_status[ $status ]['count'] : 0;
			}
		}
		$counts['failed_unresolved'] = 0;
		foreach ( $summary as $per_status ) {
			if ( isset( $per_status[ Print_Job_Service::STATUS_FAILED ] ) ) {
				$counts['failed_unresolved'] += $per_status[ Print_Job_Service::STATUS_FAILED ]['unresolved_count'];
			}
		}

		$printers = array();
		foreach ( $this->registry->get_printers() as $printer ) {
			$printer_id = (string) ( $printer['id'] ?? '' );
			if ( '' === $printer_id ) {
				continue;
			}
			// Waiting = pending + claimed: a printer that fetched a job and
			// then died leaves it claimed forever with zero pending — that
			// backlog must still trip the stale banner.
			$waiting = 0;
			$oldest  = '';
			foreach ( array( Print_Job_Service::STATUS_PENDING, Print_Job_Service::STATUS_CLAIMED ) as $status ) {
				if ( ! isset( $summary[ $printer_id ][ $status ] ) ) {
					continue;
				}
				$waiting += $summary[ $printer_id ][ $status ]['count'];
				$created  = $summary[ $printer_id ][ $status ]['oldest_gmt'];
				if ( '' !== $created && ( '' === $oldest || $created < $oldest ) ) {
					$oldest = $created;
				}
			}
			$printers[] = array(
				'printer_id'         => $printer_id,
				'name'               => (string) ( $printer['name'] ?? $printer_id ),
				// Push providers (PrintNode, Star Online) never poll, so
				// last-seen staleness is meaningless for them — the UI must
				// not show a "never fetched" banner. A missing provider
				// defaults to star-cloudprnt exactly like the print path, so
				// legacy rows without the field keep their stale warnings.
				'polling'            => Provider::is_polling(
					Provider::normalize( \is_string( $printer['provider'] ?? null ) ? $printer['provider'] : null )
				),
				'pending'            => $waiting,
				'oldest_pending_gmt' => $oldest,
				'last_seen'          => $this->registry->get_seen( $printer_id ),
			);
		}

		return rest_ensure_response(
			array(
				'jobs'     => $jobs,
				'total'    => $this->jobs->count( $filters ),
				'page'     => $page,
				'per_page' => $per_page,
				'summary'  => array(
					'counts'   => $counts,
					'printers' => $printers,
				),
			)
		);
	}

	/**
	 * Bulk-cancel waiting jobs by explicit ids or for a whole printer.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function cancel_queue( $request ) {
		$ids        = $request->get_param( 'ids' );
		$printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );

		$cancelled = $this->jobs->cancel_waiting(
			array(
				'ids'        => \is_array( $ids ) ? $ids : array(),
				'printer_id' => $printer_id,
			)
		);

		return rest_ensure_response( array( 'cancelled' => $cancelled ) );
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
		if ( $source['retried_to'] > 0 ) {
			return new WP_Error(
				'wcpos_print_job_already_retried',
				__( 'This print job has already been retried.', 'woocommerce-pos' ),
				array(
					'status'     => 409,
					'retried_to' => $source['retried_to'],
				)
			);
		}
		if ( '' === $source['payload'] && '' === $source['template_id'] ) {
			// A stripped raw job has nothing left to print — refuse loudly
			// rather than queue a blank receipt.
			return new WP_Error(
				'wcpos_print_job_source_expired',
				__( 'This job\'s stored receipt has been cleaned up and it has no template to re-render from.', 'woocommerce-pos' ),
				array( 'status' => 410 )
			);
		}
		$content_type = $source['content_type'];
		if ( '' !== $source['template_id'] ) {
			$printer = $this->registry->get_printer( (string) $source['printer_id'] );
			if ( null !== $printer ) {
				$content_type = ( new Print_Format_Resolver() )->content_type_for_printer( $printer );
			}
		}
		$new_id = $this->jobs->create(
			array(
				'printer_id'       => $source['printer_id'],
				'content_type'     => $content_type,
				'payload'          => $source['payload'],
				'order_id'         => $source['order_id'] ? $source['order_id'] : null,
				'format'           => $source['format'] ? $source['format'] : null,
				// Template-backed jobs (auto-print) carry no stored payload —
				// the render metadata must survive the copy or the reprint
				// renders nothing.
				'template_id'      => '' !== $source['template_id'] ? $source['template_id'] : null,
				'pn_kind'          => '' !== $source['pn_kind'] ? $source['pn_kind'] : null,
				'auto_open_drawer' => $source['auto_open_drawer'],
				'drawer_connector' => $source['drawer_connector'],
			)
		);
		if ( $new_id <= 0 ) {
			return new WP_Error(
				'wcpos_print_job_create_failed',
				__( 'Print job could not be created.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}
		if ( Print_Job_Service::STATUS_FAILED === $source['status'] && ! $this->jobs->mark_retried( (int) $source['id'], $new_id ) ) {
			wp_delete_post( $new_id, true );

			return new WP_Error(
				'wcpos_print_job_retry_failed',
				__( 'Print job retry could not be recorded.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		// Push providers (PrintNode, Star Online) never poll the queue — their
		// jobs only move when CRON_SUBMIT fires. Without this the replacement
		// job stays pending forever and Retry silently does nothing.
		$printer  = $this->registry->get_printer( (string) $source['printer_id'] );
		$provider = null !== $printer ? (string) ( $printer['provider'] ?? '' ) : '';
		if ( Provider::requires_submit( $provider ) ) {
			wp_schedule_single_event( time(), Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $new_id ) );
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
			return $this->cloudprnt_poll( $request, $printer_id );
		}

		$job = $this->get_cloud_job_for_request( $request, $printer_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( 'DELETE' === $request->get_method() ) {
			$code   = sanitize_text_field( (string) $request->get_param( 'code' ) );
			$status = '' === $code || '000' === $code || 1 === preg_match( '/^2\d{2,3}(?:\s|$)/', $code ) ? Print_Job_Service::STATUS_PRINTED : Print_Job_Service::STATUS_FAILED;
			$this->jobs->set_status( (int) $job['id'], $status );

			if ( Print_Job_Service::STATUS_FAILED === $status ) {
				$this->log_printer_failure( $request, $printer_id, $code, (int) $job['id'] );
			}

			return rest_ensure_response( array( 'ok' => true ) );
		}

		return $this->cloudprnt_fetch( $request, $printer_id, $job );
	}

	/**
	 * Answer a CloudPRNT poll: offer a job, and ask what the printer can decode.
	 *
	 * The poll body is the printer's half of the conversation. `printingInProgress`
	 * says a job is still on the paper, and the spec is explicit that the server
	 * must not offer another one until it clears — doing so risks the printer
	 * dropping the second job. `clientAction` carries the printer's answers to
	 * questions asked in an earlier poll response, which is the only way the
	 * protocol exposes what formats the hardware can decode.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $printer_id Printer ID.
	 *
	 * @return \WP_REST_Response
	 */
	private function cloudprnt_poll( WP_REST_Request $request, string $printer_id ) {
		$poll = Cloud_Print_Poll_Request::from_body( (string) $request->get_body(), $request->get_json_params() );
		$this->registry->record_capabilities( $printer_id, $poll->answers(), $poll->status_code() );

		$response = array( 'jobReady' => false );

		if ( ! $poll->printing_in_progress() && ! $this->jobs->find_active_claim( $printer_id ) ) {
			$job = $this->jobs->next_pending( $printer_id );
			if ( null !== $job ) {
				// The offer is a list: the printer picks its preferred decodable
				// entry and names it in the fetch's `?type`. Nothing decodable in
				// the list means no GET at all, just a 510 confirmation — so the
				// list is filtered by what this printer said it can decode.
				// `mediaType` (singular) is kept for older firmware.
				$media_types = $this->media_types_for_job( $job, $printer_id );
				$response    = array(
					'jobReady'   => true,
					'jobToken'   => (string) $job['id'],
					'mediaType'  => $media_types[0],
					'mediaTypes' => array_values( $media_types ),
				);
			}
		}

		if ( $this->registry->should_request_capabilities( $printer_id ) ) {
			$response['clientAction'] = array(
				array( 'request' => 'ClientType' ),
				array( 'request' => 'Encodings' ),
			);
			$this->registry->record_capability_request( $printer_id );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Serve a CloudPRNT job in the media type the printer asked for.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $printer_id Printer ID.
	 * @param array           $job        Job array.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	private function cloudprnt_fetch( WP_REST_Request $request, string $printer_id, array $job ) {
		// The fetch GET names the printer's chosen media type. A type the server
		// cannot produce is answered with 415 (per the CloudPRNT spec) and the job
		// is left unclaimed. What is servable is deliberately wider than what the
		// poll advertised: the printer naming a type is a stronger signal than our
		// cached capability answer, so a capability update landing between the two
		// requests must not reject a format we had just offered. Firmware that
		// omits the parameter gets our best offer for this printer instead. The
		// logged value is length-capped: printers poll every few seconds, so a
		// wedged loop must not flood the log with unbounded input.
		$servable  = ( new Cloud_Print_Media_Types() )->servable_for_job( $job, $this->registry->get_printer( $printer_id ) );
		$requested = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$chosen    = '' === $requested
			? $this->media_types_for_job( $job, $printer_id )[0]
			: Cloud_Print_Media_Types::match( $requested, $servable );

		if ( '' === $chosen ) {
			Logger::warning(
				sprintf(
					'%s: printer "%s" requested media type "%s" for print job %d, which the server can only serve as %s.',
					$request->get_route(),
					$printer_id,
					substr( $requested, 0, 100 ),
					(int) $job['id'],
					implode( ', ', $servable )
				)
			);

			return new WP_Error(
				'wcpos_print_job_incompatible_media_type',
				__( 'The print job is not available in the requested media type.', 'woocommerce-pos' ),
				array( 'status' => 415 )
			);
		}

		if ( ! $this->jobs->try_claim( (int) $job['id'] ) ) {
			return rest_ensure_response( array( 'jobReady' => false ) );
		}

		$render = $this->jobs->render_job( $job, $chosen );
		if ( '' === $render['body'] ) {
			Logger::error(
				sprintf(
					'%s: print job %d rendered an empty payload for printer "%s".',
					$request->get_route(),
					(int) $job['id'],
					$printer_id
				)
			);
		}

		return $this->serve_raw( $render['body'], $chosen, self::control_headers( $chosen, $render ) );
	}

	/**
	 * The media types a CloudPRNT job can be served in, best first.
	 *
	 * @param array  $job        Job array.
	 * @param string $printer_id Printer ID.
	 *
	 * @return array<int, string>
	 */
	private function media_types_for_job( array $job, string $printer_id ): array {
		$capabilities = $this->registry->get_capabilities( $printer_id );

		return ( new Cloud_Print_Media_Types() )->for_job(
			$job,
			$this->registry->get_printer( $printer_id ),
			$capabilities['encodings']
		);
	}

	/**
	 * Peripheral-control headers for a job served in a command-free format.
	 *
	 * `text/plain` and images carry no cut or drawer commands, so CloudPRNT reads
	 * them off the fetch response instead. Command formats express both in-band
	 * and must not also be told to cut, or the receipt cuts twice.
	 *
	 * Both headers are always sent, `none` included. Omitting them leaves the
	 * decision to the printer's own defaults, which cut plain-text jobs — so a
	 * template that deliberately does not cut would cut anyway, and would behave
	 * differently in text than in StarPRNT. Saying `none` out loud keeps the two
	 * formats rendering the same receipt.
	 *
	 * @param string $media_type The media type being served.
	 * @param array  $render     Render result from Print_Job_Service::render_job().
	 *
	 * @return array<string, string>
	 */
	private static function control_headers( string $media_type, array $render ): array {
		if ( ! Cloud_Print_Media_Types::is_header_controlled( $media_type ) ) {
			return array();
		}

		$headers = array(
			'X-Star-Cut'        => null === $render['cut'] ? 'none' : (string) $render['cut'],
			'X-Star-CashDrawer' => null === $render['drawer'] ? 'none' : (string) $render['drawer'],
		);

		// The raster is already two-colour, so the printer's Floyd-Steinberg
		// default would dither an image that has nothing left to dither —
		// softening crisp black-on-white text into stipple.
		if ( Cloud_Print_Media_Types::PNG === Cloud_Print_Media_Types::normalize( $media_type ) ) {
			$headers['X-Star-ImageDitherPattern'] = 'none';
		}

		return $headers;
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
			Logger::warning(
				sprintf(
					'%s: authentication failed for printer "%s".',
					$request->get_route(),
					$printer_id
				)
			);

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
		$job_id = (int) $request->get_param( 'token' );
		$job    = $this->jobs->get( $job_id );
		if ( null === $job || $printer_id !== $job['printer_id'] ) {
			Logger::warning(
				sprintf(
					'%s: print job "%d" was not found for printer "%s".',
					$request->get_route(),
					$job_id,
					$printer_id
				)
			);

			return new WP_Error(
				'wcpos_print_job_not_found',
				__( 'Print job not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		return $job;
	}

	/**
	 * Log a printer-reported failure without request credentials or payloads.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $printer_id Printer ID.
	 * @param string          $code       Failure code.
	 * @param int             $job_id     Print job ID.
	 */
	private function log_printer_failure( WP_REST_Request $request, string $printer_id, string $code, int $job_id ): void {
		Logger::error(
			sprintf(
				'%s: printer "%s" reported failure code "%s" for print job %d.',
				$request->get_route(),
				$printer_id,
				$code,
				$job_id
			)
		);
	}

	/**
	 * Serve raw bytes from a REST callback.
	 *
	 * @param string                $body         Response body.
	 * @param string                $content_type Content type.
	 * @param array<string, string> $headers      Extra response headers.
	 *
	 * @return \WP_REST_Response
	 */
	private function serve_raw( string $body, string $content_type, array $headers = array() ) {
		return Raw_Response::serve( $body, $content_type, $headers );
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

				if ( ! $ok ) {
					$code = 'unknown';
					if ( 1 === preg_match( '/\bcode="([^"]*)"/', $raw_body, $matches ) ) {
						$code = sanitize_text_field( $matches[1] );
					}

					$this->log_printer_failure( $request, $printer_id, $code, (int) $claim['id'] );
				}
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
		$template_id     = sanitize_text_field( (string) $request->get_param( 'template_id' ) );
		$order_id        = (int) $request->get_param( 'order_id' );
		$drawer_options = $this->drawer_options_from_request( $request );

		$printer         = $this->registry->get_printer( $printer_id );
		$is_template_job = 0 !== $order_id && '' !== $template_id;
		$validation      = $this->validate_job_for_printer( $printer, $payload, $format, $is_template_job );
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

			return $this->create_order_job( $printer_id, $printer, $order_id, $template_id, $drawer_options );
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
	 * @param string $template_id     Template id (numeric) or virtual slug.
	 * @param array  $drawer_options Drawer options.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	private function create_order_job( string $printer_id, array $printer, int $order_id, string $template_id, array $drawer_options = array() ) {
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
			$template,
			$drawer_options
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

		// Legacy printer rows saved before the provider field existed must test
		// as the default provider, not fall through to the no-diagnostic error.
		$provider = Provider::normalize( \is_string( $printer['provider'] ?? null ) ? $printer['provider'] : null );

		if ( 'printnode' === $provider ) {
			return $this->test_print_printnode( $printer );
		}

		if ( 'star-online' === $provider ) {
			return $this->test_print_star_online( $printer_id, $printer );
		}

		try {
			$diag = ( new Cloud_Print_Diagnostic() )->build( $provider, (string) $printer['name'] );
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
		$markup = ( new Cloud_Print_Diagnostic() )->star_markup( (string) $printer['name'] );

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
	 * Extract sanitized cash-drawer options from a REST request.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array{auto_open_drawer:bool, drawer_connector:string}
	 */
	private function drawer_options_from_request( WP_REST_Request $request ): array {
		$auto = $request->get_param( 'autoOpenDrawer' );
		if ( null === $auto ) {
			$auto = $request->get_param( 'auto_open_drawer' );
		}

		$connector = $request->get_param( 'drawerConnector' );
		if ( null === $connector ) {
			$connector = $request->get_param( 'drawer_connector' );
		}

		return array(
			'auto_open_drawer' => rest_sanitize_boolean( $auto ),
			'drawer_connector' => Print_Job_Service::normalize_drawer_connector( (string) $connector ),
		);
	}

	/**
	 * Validate a job against the target printer's provider.
	 *
	 * @param array|null $printer Registered printer, or null when unknown.
	 * @param string     $payload Base64 payload (raw jobs).
	 * @param string     $format  Render format (order-based jobs).
	 * @param bool       $is_template_job Whether this is an order/template job.
	 *
	 * @return true|WP_Error
	 */
	private function validate_job_for_printer( ?array $printer, string $payload, string $format, bool $is_template_job ) {
		if ( null === $printer ) {
			return true;
		}
		$provider = Provider::normalize( \is_string( $printer['provider'] ?? null ) ? $printer['provider'] : null );

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

		// The fixed-layout 'escpos' adapter emits a language StarPRNT-native
		// printers cannot decode, and the fixed-layout 'starprnt' adapter is a
		// placeholder that emits marker text, not wire bytes. Fail these jobs
		// loudly instead of queueing bytes the printer will reject.
		if ( ! $is_template_job && 'star-cloudprnt' === $provider && in_array( $format, array( 'escpos', 'starprnt' ), true ) ) {
			return new WP_Error(
				'wcpos_print_job_incompatible',
				__( 'Star CloudPRNT printers require order-based template jobs or a raw payload.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Serve the pending relay verification token (public; consent callback).
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function relay_verification() {
		$token = Cloud_Print_Relay_Service::pending_verification_token();
		if ( null === $token ) {
			return new WP_Error(
				'wcpos_relay_no_pending_verification',
				__( 'No relay verification is pending.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( array( 'token' => $token ) );
	}

	/**
	 * Register this site with the WCPOS Cloud Print relay.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function relay_register() {
		$result = Cloud_Print_Relay_Service::register_site();

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Permission check for relay registration routes.
	 *
	 * Registering rotates the site's relay credentials, so it needs the
	 * settings-management capability, not the cashier-level print capability.
	 */
	public function relay_manage_permissions_check(): bool {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * Check permissions for cashier-level print job actions.
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
