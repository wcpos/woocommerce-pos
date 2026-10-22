<?php
/**
 * Session and cash movement REST resources.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Services\Store_Reach;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Session_Figures;
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
				return $this->error( 'rest_invalid_param', 400, 'id' );
			}
			$request->set_param( 'id', strtolower( $request['id'] ) );
			$movement = '/wcpos/v2/movements' === rtrim( $request->get_route(), '/' );
			$store = $movement ? new Cash_Movement_Store() : new Register_Session_Store();
			if ( $movement && ! Pos_Uuid::is_uuid( $request['session_id'] ) ) {
				return $this->error( 'rest_invalid_param', 400, 'session_id' );
			}
			// A movement is keyed by (session, uuid): its replay is looked up in the body's session.
			$existing = $movement ? $store->get( strtolower( $request['session_id'] ), $request['id'] ) : $store->get( $request['id'] );
			/** Filter a directly addressed session or movement row on the write routes; nothing hooks it today. Read routes are checked by Store_Reach. */
			$row = apply_filters( 'wcpos_session_row', $existing, $request );
			$row = apply_filters_deprecated( 'woocommerce_pos_session_row', array( $row, $request ), '2.0.0', 'wcpos_session_row' );
			if ( substr( rtrim( $request->get_route(), '/' ), -7 ) === '/status' ) {
				return $row ? $this->change_status( $request, $row, $store ) : $this->error( 'wcpos_session_not_found', 404 );
			}
			if ( substr( rtrim( $request->get_route(), '/' ), -8 ) === '/approve' ) {
				return $row ? $this->approve( $request, $row, $store ) : $this->error( 'wcpos_session_not_found', 404 );
			}
			if ( $row ) {
				$store->log_replay( $row );
				return new WP_REST_Response( $this->visible_row( $row ) );
			}
			$fields = $movement ? $this->movement_fields( $request ) : $this->opening_fields( $request );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$result = $movement ? $store->create( $fields ) : $store->create(
				$fields,
				function ( $fields ) use ( $request ) {
					$register = ( new Register_Store() )->get( $fields['register_id'] );
					if ( ! $register || ! Store_Reach::for_request( $request )->admits( $register['store_id'] ) ) {
						return $this->error( 'rest_invalid_param', 400, 'register_id' );
					}
					$fields['store_id'] = $register['store_id'];
					return $fields;
				}
			);
			return is_wp_error( $result ) ? $result : new WP_REST_Response( $this->visible_row( $result ), 201 );
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
			$row = $store->get( strtolower( $request['id'] ) );
			// Out of reach answers exactly as absent, before the movements or figures are read.
			if ( ! $row || ! Store_Reach::for_request( $request )->admits( $row['store_id'] ) ) {
				return $this->error( 'wcpos_session_not_found', 404 );
			}
			// Capture before the reads as a fallback for clients without explicit membership.
			// Second-resolution timestamps cannot distinguish rows committed in the same second;
			// use the returned IDs, not this clock boundary, to decide snapshot membership.
			$as_of_gmt = gmdate( 'Y-m-d H:i:s' );
			$figures   = Session_Figures::for_session( $row );
			$movements = array_map( array( $this, 'visible_row' ), $figures->movements() );
			if ( substr( rtrim( $request->get_route(), '/' ), -10 ) === '/movements' ) {
				return new WP_REST_Response( $movements );
			}
			$detail = array(
				'movements' => $movements,
				'sales_count' => $figures->sales_count(),
				'order_ids' => $figures->order_ids(),
				// Keep the plain ID list for one release alongside the versioned rows.
				'ledger_row_ids' => $figures->ledger_row_ids(),
				'ledger_rows' => $figures->ledger_rows(),
				'refund_ids' => $figures->refund_ids(),
				'refunds' => $figures->refund_versions(),
				// Server-clock boundary of this snapshot (see above); never the device clock.
				'as_of_gmt' => $as_of_gmt,
			);
			// The expected figure and its terms are a report: a cashier counting blind never receives them.
			if ( current_user_can( 'view_woocommerce_pos_reports' ) ) {
				$detail['expected'] = $figures->expected();
				// The drawer equation's terms, from the snapshot expected() summed, so they add up to its cash.
				$detail['terms'] = $figures->terms();
			}
			return new WP_REST_Response( $this->visible_row( $row + $detail ) );
		}
		$args = array(
			'status' => $request['status'] ?? 'all',
			'page' => $request['page'] ?? 1,
			'per_page' => $request['per_page'] ?? 50,
		);
		if ( ! in_array( $args['status'], array( 'all', 'open', 'counting', 'closed' ), true ) ) {
			return $this->error( 'rest_invalid_param', 400, 'status' );
		}
		foreach ( array( 'page', 'per_page' ) as $key ) {
			if ( ! is_scalar( $args[ $key ] ) || ! preg_match( '/^[1-9]\d*$/D', (string) $args[ $key ] ) || ( 'per_page' === $key && $args[ $key ] > 100 ) ) {
				return $this->error( 'rest_invalid_param', 400, $key );
			}
		}
		if ( $request->has_param( 'register_id' ) ) {
			if ( ! Pos_Uuid::is_uuid( $request['register_id'] ) ) {
				return $this->error( 'rest_invalid_param', 400, 'register_id' );
			}
			$args['register_id'] = strtolower( $request['register_id'] );
		}
		$filtered = apply_filters( 'wcpos_sessions_list_args', $args, $request );
		$filtered = apply_filters_deprecated( 'woocommerce_pos_sessions_list_args', array( $filtered, $request ), '2.0.0', 'wcpos_sessions_list_args' );
		return new WP_REST_Response( array_map( array( $this, 'visible_row' ), $store->list( Store_Reach::for_request( $request )->narrow( $filtered ) ) ) );
	}

	/** Keep the count blind: without the report capability a session or movement row omits every figure.
	 *
	 * A cashier who saw the floats, the opening variance or the movement amounts and reasons could
	 * add them up to the expected drawer; approval figures stay blind even when a manager approved.
	 * One helper serves both shapes: session rows carry no amount or reason, movement rows no floats.
	 *
	 * @param array $row Response row.
	 */
	private function visible_row( array $row ): array {
		if ( ! current_user_can( 'view_woocommerce_pos_reports' ) ) {
			unset( $row['approved_expected'], $row['counted_float'], $row['expected_float'], $row['opening_variance'], $row['amount'], $row['reason'] );
		}
		return $row;
	}

	/** Validate an opening; the session's store is its register's, and the register must be in reach.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function opening_fields( $request ) {
		$register = Pos_Uuid::is_uuid( $request['register_id'] ) ? ( new Register_Store() )->get( strtolower( $request['register_id'] ) ) : null;
		if ( null === $register ) {
			return $this->error( 'rest_invalid_param', 400, 'register_id' );
		}
		// Out of reach answers as unknown, so the refusal names nothing about other stores.
		if ( ! Store_Reach::for_request( $request )->admits( $register['store_id'] ) ) {
			return $this->error( 'rest_invalid_param', 400, 'register_id' );
		}
		if ( ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $request['opened_at'] ) ) {
			return $this->error( 'rest_invalid_param', 400, 'opened_at' );
		}
		if ( null === $this->decimal( $request['counted_float'] ) ) {
			return $this->error( 'rest_invalid_param', 400, 'counted_float' );
		}
		if ( null !== $request['expected_float'] && null === $this->decimal( $request['expected_float'] ) ) {
			return $this->error( 'rest_invalid_param', 400, 'expected_float' );
		}
		if ( $request->has_param( 'business_day' ) && ! Closure_Store::is_business_day( $request['business_day'] ) ) {
			return $this->error( 'rest_invalid_param', 400, 'business_day' );
		}
		$fields = array(
			'id' => $request['id'],
			'business_day' => $request['business_day'],
			'register_id' => strtolower( $request['register_id'] ),
			'store_id' => $register['store_id'],
			'opened_at_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $request['opened_at'] ) ),
			'opened_by' => get_current_user_id(),
			'counted_float' => $this->decimal( $request['counted_float'] ),
			'expected_float' => $this->decimal( $request['expected_float'] ),
		);
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
		$counted = Closure_Store::tenders( $request['counted'] );
		if ( null === $counted ) {
			return $this->error( 'rest_invalid_param', 400, 'counted' );
		}
		$result = $store->approve( $row, $user->ID, $counted );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $this->visible_row( $result ) );
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
			return new WP_REST_Response( $this->visible_row( $row ) );
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
			return $this->error( 'rest_invalid_param', 400, 'at' );
		}
		$at = gmdate( 'Y-m-d H:i:s', strtotime( $request['at'] ) );
		$fields = array( 'status' => $status );
		if ( 'closed' === $status ) {
			$counted = Closure_Store::tenders( $request['counted'] );
			if ( null === $counted ) {
				return $this->error( 'rest_invalid_param', 400, 'counted' );
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
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $this->visible_row( $result ) );
	}

	/** Validate a movement and its same-session void target.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function movement_fields( $request ) {
		if ( ! Pos_Uuid::is_uuid( $request['session_id'] ) ) {
			return $this->error( 'rest_invalid_param', 400, 'session_id' );
		}
		if ( ! in_array( $request['type'], array( 'paid_in', 'paid_out', 'no_sale', 'void' ), true ) ) {
			return $this->error( 'rest_invalid_param', 400, 'type' );
		}
		if ( ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $request['created_at'] ) ) {
			return $this->error( 'rest_invalid_param', 400, 'created_at' );
		}
		if ( ! is_string( $request['reason'] ) || Pos_Order_Audit::char_length( $request['reason'] ) > 500 ) {
			return $this->error( 'rest_invalid_param', 400, 'reason' );
		}
		$session = ( new Register_Session_Store() )->get( strtolower( $request['session_id'] ) );
		if ( ! $session || ! ( new Cash_Movement_Store() )->accepts( $session, gmdate( 'Y-m-d H:i:s', strtotime( $request['created_at'] ) ) ) ) {
			return $this->error( 'wcpos_session_not_open', 409 );
		}
		$amount = $this->decimal( $request['amount'] );
		$paid = in_array( $request['type'], array( 'paid_in', 'paid_out' ), true );
		if ( null === $amount || ( $paid ? (float) $amount <= 0 : '' !== trim( $request['amount'], '0.' ) ) ) {
			return $this->error( 'rest_invalid_param', 400, 'amount' );
		}
		// Every movement that is not a reversal carries a reason; a no sale is no exception.
		if ( 'void' !== $request['type'] && '' === trim( sanitize_textarea_field( $request['reason'] ) ) ) {
			return $this->error( 'rest_invalid_param', 400, 'reason' );
		}
		if ( 'void' !== $request['type'] && $request->has_param( 'voids' ) ) {
			return $this->error( 'rest_invalid_param', 400, 'voids' );
		}
		$voids = null;
		if ( 'void' === $request['type'] ) {
			if ( ! Pos_Uuid::is_uuid( $request['voids'] ) ) {
				return $this->error( 'rest_invalid_param', 400, 'voids' );
			}
			$voids = strtolower( $request['voids'] );
			$target = ( new Cash_Movement_Store() )->get( $session['id'], $voids );
			if ( ! $target || null !== $target['voided_by'] || 'void' === $target['type'] ) {
				// The store's own refusal covers only the race after this check; an ordinary
				// refused reversal is decided here and must be recorded here.
				Logger::warning(
					'Cash movement refused: voids target already voided or unavailable',
					array(
						'movement_id' => $request['id'],
						'session_id' => $session['id'],
						'voids' => $voids,
						'user_id' => get_current_user_id(),
					)
				);
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
	 * A refused cash movement is money that has already physically moved, so a bare status is
	 * not enough: name the offending field in `data.params`, the shape core REST already uses,
	 * so the till can point at the input and the log row can record which one it was.
	 *
	 * @param string      $code Error code.
	 * @param int         $status HTTP status.
	 * @param string|null $field Offending request field, when one can be named.
	 */
	private function error( string $code, int $status, ?string $field = null ): WP_Error {
		$data = array( 'status' => $status );
		if ( null !== $field ) {
			/* translators: %s: name of the request field that was rejected. */
			$data['params'] = array( $field => sprintf( __( 'Invalid parameter: %s', 'woocommerce-pos' ), $field ) );
		}
		return new WP_Error( $code, __( 'The session request could not be completed.', 'woocommerce-pos' ), $data );
	}
}
