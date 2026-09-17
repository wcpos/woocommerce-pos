<?php
/**
 * Print Jobs REST controller.
 *
 * @package WCPOS\WooCommercePOS\API\V1
 */

namespace WCPOS\WooCommercePOS\API\V1;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Interfaces\Push_Provider_Adapter_Interface;
use WCPOS\WooCommercePOS\Services\Print_Job_Lifecycle;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Relay_Service;
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
	 * Legacy Epson timeout.
	 *
	 * @deprecated Use Epson_Sdp_Adapter::EPSON_SDP_PRINT_TIMEOUT_MS.
	 */
	const EPSON_SDP_PRINT_TIMEOUT_MS = \WCPOS\WooCommercePOS\Services\Providers\Epson_Sdp_Adapter::EPSON_SDP_PRINT_TIMEOUT_MS;

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
			'/' . $this->rest_base . '/queue/delete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'delete_queue' ),
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
	 * Sanitize a job filter that may arrive as a scalar or as a list.
	 *
	 * These routes declare no arg schema, so a caller can send `status=failed`
	 * or `status[]=pending&status[]=failed`. filters_to_meta_query() turns a
	 * list into an IN clause, so flattening one to a string here would silently
	 * narrow the query (and warn on the array-to-string cast).
	 *
	 * @param mixed $value Raw request parameter.
	 *
	 * @return array|string
	 */
	private function sanitize_filter( $value ) {
		if ( \is_array( $value ) ) {
			return array_map(
				function ( $item ): string {
					return sanitize_text_field( \is_scalar( $item ) ? (string) $item : '' );
				},
				$value
			);
		}

		return sanitize_text_field( \is_scalar( $value ) ? (string) $value : '' );
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
					'printer_id' => $this->sanitize_filter( $request->get_param( 'printer_id' ) ),
					'status'     => $this->sanitize_filter( $request->get_param( 'status' ) ),
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

		// Without force this route cancels: it takes a waiting job out of the
		// running but leaves the row as history. With force the row goes for
		// good, which is the only way to clear a terminal job before its
		// retention window expires.
		if ( rest_sanitize_boolean( $request->get_param( 'force' ) ) ) {
			if ( ! $this->jobs->delete( $id ) ) {
				return new WP_Error(
					'wcpos_print_job_not_deleted',
					__( 'The print job could not be deleted.', 'woocommerce-pos' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'deleted'  => true,
					'previous' => $job,
				)
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

		$status          = $this->sanitize_filter( $request->get_param( 'status' ) );
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
			'printer_id'      => $this->sanitize_filter( $request->get_param( 'printer_id' ) ),
			'status'          => $status,
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
						// Newest first: the job an admin opens the queue to check
						// on is the one that just fired, not the oldest survivor.
						'order' => 'DESC',
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
	 * Permanently delete queue rows.
	 *
	 * Unlike cancel_queue(), this removes history: any status may be deleted,
	 * because the point is clearing a queue the admin no longer wants to look
	 * at rather than stopping work. Waiting jobs are cancelled on the way out
	 * so nothing is left half-claimed.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete_queue( $request ) {
		$ids = $request->get_param( 'ids' );
		if ( ! \is_array( $ids ) || empty( $ids ) ) {
			return new WP_Error(
				'wcpos_print_job_no_ids',
				__( 'No print jobs were selected.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}
		foreach ( $ids as $id ) {
			if ( ( ! \is_int( $id ) && ! \is_string( $id ) ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) {
				return new WP_Error(
					'wcpos_print_job_invalid_ids',
					__( 'One or more selected print jobs are invalid.', 'woocommerce-pos' ),
					array( 'status' => 400 )
				);
			}
		}

		$deleted = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( $id > 0 && $this->jobs->delete( $id ) ) {
				++$deleted;
			}
		}

		return rest_ensure_response( array( 'deleted' => $deleted ) );
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
		$pn_kind      = $source['pn_kind'];
		if ( '' !== $source['template_id'] ) {
			$template = Print_Job_Service::load_template( (string) $source['template_id'] );
			if ( null === $template && $source['order_id'] > 0 ) {
				// render_payload() takes its template branch on
				// order_id + template_id and returns nothing when the
				// template is gone — the stored payload is never reached.
				// Queueing here would 201 a job that can only ever fail,
				// so refuse for the same reason the stripped-payload guard
				// above does.
				return new WP_Error(
					'wcpos_print_job_source_expired',
					__( 'This job\'s template no longer exists, so it cannot be re-rendered.', 'woocommerce-pos' ),
					array( 'status' => 410 )
				);
			}
			$printer = $this->registry->get_printer( (string) $source['printer_id'] );
			if ( null !== $printer ) {
				// Provider::format() refreshes both halves together. A legacy job
				// can carry a media type from before the provider declared its own,
				// but content_type and pn_kind must keep agreeing: reprinting a
				// raw (escpos) PrintNode job through printer_content_type()
				// relabels it application/pdf in the queue view, even though
				// submit still sends raw bytes off the stored pn_kind.
				$fmt = null === $template ? array( 'kind' => '' ) : Provider::format( $printer, $template );
				if ( '' === (string) $fmt['kind'] ) {
					// No loadable template, or one this printer can no longer
					// render. Refresh from the provider's declared type only
					// when no stored kind can contradict it; otherwise the
					// source pairing is the best answer left.
					if ( '' === $pn_kind ) {
						$content_type = Provider::printer_content_type( $printer );
					}
				} else {
					$content_type = $fmt['content_type'];
					$pn_kind      = Provider::stores_job_kind( Provider::normalize( (string) ( $printer['provider'] ?? '' ) ) )
						? $fmt['kind']
						: '';
				}
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
				'pn_kind'          => '' !== $pn_kind ? $pn_kind : null,
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
		return $this->handle_provider_poll( $request, 'star-cloudprnt' );
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
	 * Parse the vendor request and serve the lifecycle's plain-data response.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $provider_key Protocol selected by the route.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_provider_poll( WP_REST_Request $request, string $provider_key ) {
		$printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );
		$printer = $this->registry->get_printer( $printer_id );
		if ( null === $printer ) {
			return new WP_Error( 'wcpos_print_job_invalid_token', __( 'Invalid printer token.', 'woocommerce-pos' ), array( 'status' => 401 ) );
		}
		$poll = Provider::poll_adapter( $provider_key )->parse(
			array(
				'params' => $request->get_params(),
				'body'   => (string) $request->get_body(),
				'json'   => 'POST' === $request->get_method() ? $request->get_json_params() : null,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			)
		);
		$result = ( new Print_Job_Lifecycle( $this->jobs, $this->registry ) )->handle_poll( $provider_key, $printer, $poll );
		if ( \is_string( $result['body'] ) ) {
			$response = $this->serve_raw( $result['body'], $result['headers']['Content-Type'], $result['headers'] );
			$response->set_status( $result['status'] );
			return $response;
		}
		return new WP_REST_Response( $result['body'], $result['status'], $result['headers'] );
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
		return $this->handle_provider_poll( $request, 'epson-sdp' );
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

		$adapter = Provider::adapter( $provider );
		if ( $adapter instanceof Push_Provider_Adapter_Interface ) {
			return $adapter->test_print( $printer );
		}
		$diag = $adapter->diagnostic( (string) $printer['name'] );

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
