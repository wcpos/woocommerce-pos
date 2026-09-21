<?php
/**
 * Report catalogue and server-produced documents.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Report_Document_Validator;
use WCPOS\WooCommercePOS\Services\Report_Scope_Gate;
use WCPOS\WooCommercePOS\Services\Report_Scope_Resolver;
use WCPOS\WooCommercePOS\Services\Reports_Registry;
use WP_Error;
use WP_REST_Response;

/** No built-in report is computed on the server. */
class Reports_Controller extends \WP_REST_Controller {
	/** REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';
	/** Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'reports';

	/** Register catalogue and document reads. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		// No item schema on the document route: it answers a report document described by the
		// `report` JSON schema, not a catalogue entry, and advertising the registration shape
		// there would misdescribe it. A schema key must be a callable when present.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<key>[a-z0-9_]+)',
			array(
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/** Reads use the ordinary app protocol headers. */
	public function wcpos_route_classifications(): array {
		return array();
	}

	/**
	 * Both routes sit behind the Reports floor; get_item() adds the report's declared capability.
	 *
	 * The floor is checked here rather than inside get_item() so a blind cashier cannot
	 * distinguish a missing key from a device-computed one, or probe parameter validation,
	 * before being refused: they cannot open Reports at all.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permissions_check( $request ) {
		return $this->authorize();
	}

	/**
	 * Return only visible public declarations, never callables or capabilities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$reports = array();
		foreach ( Reports_Registry::all() as $key => $report ) {
			if ( current_user_can( $report['capability'] ) ) {
				$entry = array( 'key' => $key );
				foreach ( array( 'title', 'scopes', 'group_by', 'source', 'tile', 'template' ) as $field ) {
					$entry[ $field ] = $report[ $field ];
				}
				$reports[] = $entry;
			}
		}
		return new WP_REST_Response( array( 'reports' => $reports ) );
	}

	/**
	 * Resolve declaration, validate, authorize, gate, resolve, then invoke and validate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$key = $request->get_url_params()['key'];
		$report = Reports_Registry::all()[ $key ] ?? null;
		// A report whose declared capability the caller lacks is hidden, not refused, and hidden
		// *before* its source or its parameters are inspected. The catalogue already omits it, so
		// answering 403 here — or 404 device_computed, or a parameter-specific 400 — would hand
		// back the registration the catalogue withheld, along with the parameters it accepts.
		if ( null === $report || ! current_user_can( $report['capability'] ) ) {
			return new WP_Error( 'wcpos_report_not_found', __( 'Report not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		if ( 'device' === $report['source'] ) {
			return new WP_Error( 'wcpos_report_is_device_computed', __( 'The device builds this report locally; there is no server document.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$args = $this->parameters( $request->get_params(), $report );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$context = Report_Scope_Resolver::context( $args, $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$allowed = Report_Scope_Gate::check( $context );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$scope = Report_Scope_Resolver::resolve( $context );
		try {
			$core = ( $report['callback'] )( $scope );
			if ( ! is_array( $core ) ) {
				return $this->failed( $key, __( 'The report callback must return an array.', 'woocommerce-pos' ) );
			}
			// The builder owns build → woocommerce_pos_report_data → restore identity, so that the
			// report path and the closure path protect their documents the same way.
			$data = ( new Receipt_Data_Builder() )->build_report_document( $key, $report['title'], $scope, $core );
			$valid = Report_Document_Validator::validate( $data );
			return is_wp_error( $valid ) ? $this->failed( $key, $valid->get_error_message() ) : new WP_REST_Response( $data );
		} catch ( \Throwable $error ) {
			Logger::log(
				'Report failed',
				array(
					'key' => $key,
					'exception' => get_class( $error ),
					'message' => $error->getMessage(),
				)
			);
			return $this->failed( $key, $error->getMessage() );
		}
	}

	/**
	 * Validate every declared parameter without forwarding arbitrary request keys.
	 *
	 * @param array $params Request values.
	 * @param array $report Normalized declaration.
	 * @return array|WP_Error
	 */
	private function parameters( array $params, array $report ) {
		if ( ! in_array( $params['mode'] ?? null, $report['scopes'], true ) ) {
			return new WP_Error( 'wcpos_report_scope_unsupported', __( 'This report does not support the requested scope.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$args = array_intersect_key( $params, array_flip( array( 'mode', 'register_id', 'store_id', 'session_id', 'from', 'to', 'group_by' ) ) );
		foreach ( array( 'register_id', 'session_id' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				if ( ! is_string( $args[ $field ] ) || ! preg_match( '/^[0-9a-fA-F-]{36}$/D', $args[ $field ] ) ) {
					return $this->invalid( $field );
				}
				$args[ $field ] = strtolower( $args[ $field ] );
			}
		}
		if ( array_key_exists( 'store_id', $args ) ) {
			if ( ! ( is_int( $args['store_id'] ) || is_string( $args['store_id'] ) ) || ! preg_match( '/^\d+$/D', (string) $args['store_id'] ) ) {
				return $this->invalid( 'store_id' );
			}
			$args['store_id'] = (int) $args['store_id'];
		}
		if ( array_key_exists( 'group_by', $args ) && ! in_array( $args['group_by'], array_column( $report['group_by'], 'key' ), true ) ) {
			return $this->invalid( 'group_by' );
		}
		foreach ( array( 'from', 'to' ) as $field ) {
			if ( ( 'range' === $args['mode'] || array_key_exists( $field, $args ) ) && ! Closure_Store::is_business_day( $args[ $field ] ?? null ) ) {
				return $this->invalid( $field );
			}
		}
		if ( 'session' === $args['mode'] && ! isset( $args['session_id'] ) ) {
			return $this->invalid( 'session_id' );
		}
		if ( isset( $args['from'], $args['to'] ) && $args['to'] < $args['from'] ) {
			return $this->invalid( 'to' );
		}
		return $args;
	}

	/**
	 * The Reports floor, which both routes sit behind.
	 *
	 * This is the visible boundary: a blind cashier cannot open Reports at all, and is told so
	 * with a 403. A report's own declared capability is a *visibility* boundary instead, checked
	 * in get_item() and answered as absent, so it is not handled here.
	 *
	 * @return true|WP_Error
	 */
	private function authorize() {
		foreach ( array( 'access_woocommerce_pos', Reports_Registry::DEFAULT_CAPABILITY ) as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'wcpos_report_forbidden', __( 'Sorry, you cannot access this report.', 'woocommerce-pos' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/** Public catalogue entry schema. */
	public function get_item_schema(): array {
		$properties = array();
		foreach ( array( 'key', 'title', 'source' ) as $field ) {
			$properties[ $field ] = array(
				'type' => 'string',
				'readonly' => true,
			);
		}
		$properties['source']['enum'] = array( 'device', 'server' );
		$properties['scopes'] = array(
			'type' => 'array',
			'items' => array(
				'type' => 'string',
				'enum' => array( 'session', 'range' ),
			),
			'readonly' => true,
		);
		$properties['group_by'] = array(
			'type' => 'array',
			'items' => array(
				'type' => 'object',
				'properties' => array(
					'key' => array( 'type' => 'string' ),
					'label' => array( 'type' => 'string' ),
				),
			),
			'readonly' => true,
		);
		$properties['tile'] = array(
			'type' => array( 'object', 'null' ),
			'properties' => array(
				'number' => array( 'type' => 'string' ),
				'line' => array( 'type' => 'string' ),
			),
			'readonly' => true,
		);
		$properties['template'] = array(
			'type' => array( 'string', 'null' ),
			'readonly' => true,
		);
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'report_registration',
			'type' => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * Invalid parameter response.
	 *
	 * @param string $field Field name.
	 */
	private function invalid( string $field ): WP_Error {
		/* translators: %s: report query field. */
		return new WP_Error( 'rest_invalid_param', sprintf( __( 'Invalid report field: %s.', 'woocommerce-pos' ), $field ), array( 'status' => 400 ) );
	}

	/**
	 * A producer failure always names the report, without exposing a stack trace.
	 *
	 * @param string $key Report key.
	 * @param string $message Failure message.
	 */
	private function failed( string $key, string $message ): WP_Error {
		return new WP_Error(
			'wcpos_report_failed',
			/* translators: 1: report key, 2: the failure message from the report's plugin. */
			sprintf( __( 'Report "%1$s" failed: %2$s', 'woocommerce-pos' ), $key, $message ),
			array(
				'status' => 500,
				'key' => $key,
			)
		);
	}
}
