<?php
/**
 * Session and cash movement REST resources.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;
use WP_REST_Response;

/** Cash bookkeeping; permissions and input validation live at this boundary. */
class Sessions_Controller extends \WP_REST_Controller {
	/** REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';
	/** Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'sessions';

	/** Register session reads, state writes and movement inserts. */
	public function register_routes(): void {
		foreach ( array(
			'/sessions' => 'GET,POST',
			'/sessions/(?P<id>[0-9a-fA-F-]{36})' => 'GET',
			'/sessions/(?P<id>[0-9a-fA-F-]{36})/status' => 'POST',
			'/sessions/(?P<id>[0-9a-fA-F-]{36})/approve' => 'POST',
			'/sessions/(?P<id>[0-9a-fA-F-]{36})/movements' => 'GET',
			'/movements' => 'POST',
		) as $route => $methods ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					'methods' => $methods,
					'callback' => array( $this, 'dispatch' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				)
			);
		}
	}

	/** Queue replay and cookie requests do not need a protocol claim. */
	public function wcpos_route_classifications(): array {
		return array( 'protocol_exempt' => array( '/wcpos/v2/sessions', '/wcpos/v2/movements' ) );
	}

	/** Require cash management for every write, POS access for reads.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		return current_user_can( 'POST' === $request->get_method() ? 'manage_woocommerce_pos_cash' : 'access_woocommerce_pos' ) ? true : $this->error( 'rest_forbidden', rest_authorization_required_code() );
	}

	/** Dispatch the two thin resources, surfacing storage failures.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dispatch( $request ) {
		try {
			if ( 'POST' !== $request->get_method() ) {
				return $this->read( $request );
			}
			if ( ! Pos_Uuid::is_uuid( $request['id'] ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			$request->set_param( 'id', strtolower( $request['id'] ) );
			$movement = '/wcpos/v2/movements' === rtrim( $request->get_route(), '/' );
			$store = $movement ? new Cash_Movement_Store() : new Register_Session_Store();
			/** Filter a directly addressed session or movement row; Pro returns null outside the caller's stores. */
			$row = apply_filters( 'woocommerce_pos_session_row', $store->get( $request['id'] ), $request );
			if ( substr( rtrim( $request->get_route(), '/' ), -7 ) === '/status' ) {
				return $row ? $this->change_status( $request, $row, $store ) : $this->error( 'wcpos_session_not_found', 404 );
			}
			if ( substr( rtrim( $request->get_route(), '/' ), -8 ) === '/approve' ) {
				return $row ? $this->approve( $request, $row, $store ) : $this->error( 'wcpos_session_not_found', 404 );
			}
			if ( $row ) {
				return new WP_REST_Response( $row );
			}
			$fields = $movement ? $this->movement_fields( $request ) : $this->opening_fields( $request );
			$result = is_wp_error( $fields ) ? $fields : $store->create( $fields );
			return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
		} catch ( \RuntimeException $error ) {
			return $this->error( 'wcpos_session_write_failed', 500 );
		}
	}

	/** Read collection, details or the movement log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function read( $request ) {
		$store = new Register_Session_Store();
		if ( $request['id'] ) {
			$row = apply_filters( 'woocommerce_pos_session_row', $store->get( strtolower( $request['id'] ) ), $request );
			if ( ! $row ) {
				return $this->error( 'wcpos_session_not_found', 404 );
			}
			$movements = ( new Cash_Movement_Store() )->list( $row['id'] );
			return new WP_REST_Response(
				substr( rtrim( $request->get_route(), '/' ), -10 ) === '/movements' ? $movements : $row + array(
					'movements' => $movements,
					'expected' => $store->expected( $row ),
					'sales_count' => $store->sales_count( $row ),
				)
			);
		}
		$args = array(
			'status' => $request['status'] ?? 'all',
			'page' => $request['page'] ?? 1,
			'per_page' => $request['per_page'] ?? 50,
		);
		if ( ! in_array( $args['status'], array( 'all', 'open', 'counting', 'closed' ), true ) ) {
			return $this->error( 'rest_invalid_param', 400 );
		}
		foreach ( array( 'page', 'per_page' ) as $key ) {
			if ( ! is_scalar( $args[ $key ] ) || ! preg_match( '/^[1-9]\d*$/D', (string) $args[ $key ] ) || ( 'per_page' === $key && $args[ $key ] > 100 ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
		}
		if ( $request->has_param( 'register_id' ) ) {
			if ( ! Pos_Uuid::is_uuid( $request['register_id'] ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			$args['register_id'] = strtolower( $request['register_id'] );
		}
		return new WP_REST_Response( $store->list( apply_filters( 'woocommerce_pos_sessions_list_args', $args, $request ) ) );
	}

	/** Validate an opening, retaining only the scoped store id from extensions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function opening_fields( $request ) {
		if ( ! Pos_Uuid::is_uuid( $request['register_id'] ) || ! ( new Register_Store() )->exists( $request['register_id'] ) || ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $request['opened_at'] ) || null === $this->decimal( $request['counted_float'] ) || ( null !== $request['expected_float'] && null === $this->decimal( $request['expected_float'] ) ) ) {
			return $this->error( 'rest_invalid_param', 400 );
		}
		$fields = array(
			'id' => $request['id'],
			'register_id' => strtolower( $request['register_id'] ),
			'store_id' => $request['store_id'],
			'opened_at_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $request['opened_at'] ) ),
			'opened_by' => get_current_user_id(),
			'counted_float' => $this->decimal( $request['counted_float'] ),
			'expected_float' => $this->decimal( $request['expected_float'] ),
		);
		$scoped = apply_filters( 'woocommerce_pos_session_create_fields', $fields, $request );
		$fields['store_id'] = $scoped['store_id'] ?? null;
		if ( null !== $fields['store_id'] && ( ! is_scalar( $fields['store_id'] ) || ! preg_match( '/^\d{1,18}$/D', (string) $fields['store_id'] ) ) ) {
			return $this->error( 'rest_invalid_param', 400 );
		}
		return $fields;
	}

	/** Check manager credentials for this request only; persist only the approver id.
	 *
	 * @param \WP_REST_Request       $request Request.
	 * @param array                  $row Current row.
	 * @param Register_Session_Store $store Owner.
	 * @return WP_REST_Response|WP_Error
	 */
	private function approve( $request, array $row, Register_Session_Store $store ) {
		if ( 'counting' !== $row['status'] ) {
			return $this->error( 'wcpos_session_transition_refused', 409 );
		}
		if ( ! is_string( $request['username'] ) || ! is_string( $request['password'] ) ) {
			return $this->error( 'wcpos_override_refused', 403 );
		}
		$user = wp_authenticate( $request['username'], $request['password'] );
		if ( is_wp_error( $user ) || get_current_user_id() === $user->ID || ! user_can( $user, 'manage_woocommerce_pos_closures' ) ) {
			return $this->error( 'wcpos_override_refused', 403 );
		}
		$result = $store->approve( $row, $user->ID );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/** Validate a state transition and optional second-user authorization.
	 *
	 * @param \WP_REST_Request       $request Request.
	 * @param array                  $row Current row.
	 * @param Register_Session_Store $store Owner.
	 * @return WP_REST_Response|WP_Error
	 */
	private function change_status( $request, array $row, Register_Session_Store $store ) {
		$status = $request['status'];
		if ( $status === $row['status'] ) {
			return new WP_REST_Response( $row );
		}
		$allowed = array(
			'open' => array( 'counting' ),
			'counting' => array( 'open', 'closed' ),
			'closed' => array(),
		);
		if ( ! in_array( $status, $allowed[ $row['status'] ], true ) ) {
			return $this->error( 'wcpos_session_transition_refused', 409 );
		}
		if ( ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $request['at'] ) ) {
			return $this->error( 'rest_invalid_param', 400 );
		}
		$at = gmdate( 'Y-m-d H:i:s', strtotime( $request['at'] ) );
		$fields = array( 'status' => $status );
		if ( 'closed' === $status ) {
			$counted = $request['counted'];
			if ( ! is_array( $counted ) || ! isset( $counted['cash'] ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			foreach ( $counted as &$amount ) {
				$amount = $this->decimal( $amount );
				if ( null === $amount ) {
					return $this->error( 'rest_invalid_param', 400 );
				}
			}
			$fields += array(
				'counted' => $counted,
				'closed_at_gmt' => $at,
				'closed_by' => get_current_user_id(),
			);
		} else {
			$fields['counting_started_at_gmt'] = 'counting' === $status ? $at : null;
		}
		if ( 'closed' === $status && $request->has_param( 'approver_token' ) ) {
			$token = is_string( $request['approver_token'] ) ? Auth::instance()->validate_token( $request['approver_token'] ) : null;
			$user = ! $token || is_wp_error( $token ) ? 0 : (int) $token->data->user->id;
			if ( ! $user || get_current_user_id() === $user || ! user_can( $user, 'manage_woocommerce_pos_closures' ) ) {
				return $this->error( 'wcpos_override_refused', 403 );
			}
			$fields['approved_by'] = $user;
		}
		$result = $store->transition( $row, $fields );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/** Validate a movement and its same-session void target.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function movement_fields( $request ) {
		if ( ! Pos_Uuid::is_uuid( $request['session_id'] ) || ! in_array( $request['type'], array( 'paid_in', 'paid_out', 'no_sale', 'void' ), true ) || ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $request['created_at'] ) || ! is_string( $request['reason'] ) || Pos_Order_Audit::char_length( $request['reason'] ) > 500 ) {
			return $this->error( 'rest_invalid_param', 400 );
		}
		$session = ( new Register_Session_Store() )->get( strtolower( $request['session_id'] ) );
		if ( ! $session || 'open' !== $session['status'] ) {
			return $this->error( 'wcpos_session_not_open', 409 );
		}
		$amount = $this->decimal( $request['amount'] );
		$paid = in_array( $request['type'], array( 'paid_in', 'paid_out' ), true );
		if ( null === $amount || ( $paid ? (float) $amount <= 0 : '' !== trim( $request['amount'], '0.' ) ) || ( 'void' !== $request['type'] && ( '' === trim( sanitize_textarea_field( $request['reason'] ) ) || $request->has_param( 'voids' ) ) ) ) {
			return $this->error( 'rest_invalid_param', 400 );
		}
		$voids = null;
		if ( 'void' === $request['type'] ) {
			if ( ! Pos_Uuid::is_uuid( $request['voids'] ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			$voids = strtolower( $request['voids'] );
			$target = ( new Cash_Movement_Store() )->get( $voids );
			if ( ! $target || $target['session_id'] !== $session['id'] || null !== $target['voided_by'] || 'void' === $target['type'] ) {
				return $this->error( 'wcpos_movement_void_refused', 409 );
			}
		}
		return array(
			'id' => $request['id'],
			'session_id' => $session['id'],
			'type' => $request['type'],
			'amount' => $amount,
			'reason' => sanitize_textarea_field( $request['reason'] ),
			'actor' => get_current_user_id(),
			'voids' => $voids,
			'created_at_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $request['created_at'] ) ),
		);
	}

	/** Normalize unsigned decimals in SQL to preserve all nineteen digits.
	 *
	 * @param mixed $value Input.
	 */
	private function decimal( $value ): ?string {
		global $wpdb;
		if ( ! is_string( $value ) || ! preg_match( '/^\d+(?:\.\d+)?$/D', $value ) || strlen( ltrim( explode( '.', $value )[0], '0' ) ) > 15 ) {
			return null;
		}
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT CAST(%s AS DECIMAL(65,4))', $value ) );
		return is_string( $value ) && preg_match( '/^\d{1,15}\.\d{4}$/D', $value ) ? $value : null;
	}

	/** Shared error envelope.
	 *
	 * @param string $code Error code.
	 * @param int    $status HTTP status.
	 */
	private function error( string $code, int $status ): WP_Error {
		return new WP_Error( $code, __( 'The session request could not be completed.', 'woocommerce-pos' ), array( 'status' => $status ) );
	}
}
