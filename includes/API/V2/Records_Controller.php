<?php
/**
 * Read-only fiscal history REST resource.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use DateTimeImmutable;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/** Exposes fiscal records without any REST write route. */
class Records_Controller extends WP_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';
	/**
	 * Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'records';

	/** Register only collection and item reads. */
	public function register_routes(): void {
		foreach ( array(
			'' => 'get_items',
			'/(?P<id>\d+)' => 'get_item',
		) as $suffix => $callback ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . $suffix,
				array(
					array(
						'methods' => 'GET',
						'callback' => array( $this, $callback ),
						'permission_callback' => array( $this, 'records_permissions_check' ),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);
		}
	}

	/** Like registers, history reads carry no client protocol claim. */
	public function wcpos_route_classifications(): array {
		// Read only through the app (protocol headers present); no wp-admin screen reads it yet.
		return array();
	}

	/**
	 * Require POS access.
	 *
	 * @return bool|WP_Error
	 */
	public function records_permissions_check() {
		return current_user_can( 'access_woocommerce_pos' ) ? true : new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot access fiscal records.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Validate query filters before extensions authorize store scope.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$args = array(
			'page' => 1,
			'per_page' => Fiscal_Record_Store::DEFAULT_PER_PAGE,
		);
		foreach ( array( 'order_id', 'closure_id', 'page', 'per_page', 'store_id' ) as $key ) {
			if ( ! $request->has_param( $key ) ) {
				continue;
			}
			$values = 'store_id' === $key && is_array( $request[ $key ] ) ? $request[ $key ] : array( $request[ $key ] );
			foreach ( $values as $value ) {
				if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/D', (string) $value ) || (int) $value < 1 || ( 'per_page' === $key && (int) $value > Fiscal_Record_Store::MAX_PER_PAGE ) ) {
					return $this->invalid( $key );
				}
			}
			$args[ $key ] = is_array( $request[ $key ] ) ? array_map( 'intval', $values ) : (int) $request[ $key ];
		}
		foreach ( array( 'register_id', 'session_id' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				if ( ! Pos_Uuid::is_uuid( $request[ $key ] ) ) {
					return $this->invalid( $key );
				}
				$args[ $key ] = strtolower( $request[ $key ] );
			}
		}
		if ( $request->has_param( 'type' ) ) {
			if ( ! in_array( $request['type'], Fiscal_Record_Store::TYPES, true ) ) {
				return $this->invalid( 'type' );
			}
			$args['type'] = $request['type'];
		}
		foreach ( array( 'after', 'before' ) as $key ) {
			if ( ! $request->has_param( $key ) ) {
				continue;
			}
			$value = $request[ $key ];
			if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}$/D', $value ) ) {
				return $this->invalid( $key );
			}
			$value = str_replace( 'T', ' ', $value );
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
				return $this->invalid( $key );
			}
			$args[ $key ] = $value;
		}
		/** Filter authorized history scope; Pro may constrain store_id. */
		$args = apply_filters( 'woocommerce_pos_records_list_args', $args, $request );
		$store = new Fiscal_Record_Store();
		$response = new WP_REST_Response( $store->list( $args ) );
		$total = $store->count( $args );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ceil( $total / max( 1, min( Fiscal_Record_Store::MAX_PER_PAGE, (int) $args['per_page'] ) ) ) );
		return $response;
	}

	/**
	 * Fetch an immutable record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$row = ( new Fiscal_Record_Store() )->get( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found();
		}
		// The same store scope the list applies (Pro sets store_id); out of scope reads as absent.
		$args = apply_filters( 'woocommerce_pos_records_list_args', array(), $request );
		if ( isset( $args['store_id'] ) && ! in_array( (int) $row['store_id'], array_map( 'intval', (array) $args['store_id'] ), true ) ) {
			return $this->not_found();
		}
		return new WP_REST_Response( $row );
	}

	/** Describe the read-only resource. */
	public function get_item_schema(): array {
		$properties = array();
		foreach ( array( 'id', 'number', 'order_id', 'refund_id', 'closure_id', 'corrects_record_id', 'store_id', 'cashier_id', 'approver_id', 'print_count' ) as $key ) {
			$properties[ $key ] = array(
				'type' => in_array( $key, array( 'id', 'number', 'print_count' ), true ) ? 'integer' : array( 'integer', 'null' ),
				'readonly' => true,
			);
		}
		foreach ( array( 'type', 'series', 'payment_id', 'source_id', 'register_id', 'session_id', 'device_time', 'device_tz', 'received_at_gmt', 'checksum', 'last_printed_at_gmt' ) as $key ) {
			$properties[ $key ] = array(
				'type' => in_array( $key, array( 'type', 'series', 'received_at_gmt', 'checksum' ), true ) ? 'string' : array( 'string', 'null' ),
				'readonly' => true,
			);
		}
		$properties['type']['enum'] = Fiscal_Record_Store::TYPES;
		$properties['payload'] = array(
			'type' => 'object',
			'readonly' => true,
		);
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'fiscal_record',
			'type' => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * Invalid query field.
	 *
	 * @param string $field Field name.
	 */
	private function invalid( string $field ): WP_Error {
		/* translators: %s: fiscal record query field. */
		return new WP_Error( 'rest_invalid_param', sprintf( __( 'Invalid fiscal record field: %s.', 'woocommerce-pos' ), $field ), array( 'status' => 400 ) );
	}

	/** Missing record response. */
	private function not_found(): WP_Error {
		return new WP_Error( 'wcpos_record_not_found', __( 'Fiscal record not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
	}
}
