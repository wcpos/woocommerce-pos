<?php
/**
 * Register closure REST resource.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;
use WP_REST_Response;

/** Permissions and client input validation for immutable closures. */
class Closures_Controller extends \WP_REST_Controller {
	/** REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';
	/** Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'closures';

	/** Register documents, copies and recounts. */
	public function register_routes(): void {
		foreach ( array(
			'/closures' => 'GET,POST',
			'/closures/last' => 'GET',
			'/closures/(?P<closure_id>[0-9a-fA-F-]{36})' => 'GET',
			'/closures/(?P<closure_id>[0-9a-fA-F-]{36})/print' => 'POST',
			'/closures/(?P<closure_id>[0-9a-fA-F-]{36})/recount' => 'POST',
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

	/** Offline replay and cookie requests do not require a protocol claim. */
	public function wcpos_route_classifications(): array {
		return array( 'protocol_exempt' => array( '/wcpos/v2/closures' ) );
	}

	/** Reads, cash writes and manager-only recounts have distinct capabilities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		$route = strtolower( rtrim( $request->get_route(), '/' ) );
		if ( '/recount' === substr( $route, -8 ) ) {
			$cap = 'manage_woocommerce_pos_closures';
		} elseif ( 'POST' === $request->get_method() ) {
			$cap = 'manage_woocommerce_pos_cash';
		} else {
			// Writing the document is a cash duty; reading it back is also a report.
			if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
				return $this->error( 'rest_forbidden', rest_authorization_required_code() );
			}
			$cap = 'view_woocommerce_pos_reports';
		}
		if ( ! current_user_can( $cap ) ) {
			return $this->error( 'rest_forbidden', rest_authorization_required_code() );
		}
		// A print hands over the figures, so a blind cashier may write a closure but never print one.
		if ( '/print' === substr( $route, -6 ) && ! current_user_can( 'view_woocommerce_pos_reports' ) ) {
			return $this->error( 'rest_forbidden', rest_authorization_required_code() );
		}
		return true;
	}

	/** Dispatch, preserving the URL document UUID separately from a recount's body id.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dispatch( $request ) {
		try {
			$store = new Closure_Store();
			$url = $request->get_url_params();
			$id = isset( $url['closure_id'] ) ? strtolower( $url['closure_id'] ) : null;
			$route = strtolower( rtrim( $request->get_route(), '/' ) );
			if ( null !== $id ) {
				$row = $store->get( $id );
				if ( ! $row ) {
					return $this->error( 'wcpos_closure_not_found', 404 );
				}
				if ( '/print' === substr( $route, -6 ) ) {
					$row = $store->record_print( $id );
				} elseif ( '/recount' === substr( $route, -8 ) ) {
					$counted = $this->tenders( $request['counted'] );
					if ( ! Pos_Uuid::is_uuid( $request['id'] ) || null === $counted || ! is_string( $request['reason'] ) || Pos_Order_Audit::char_length( $request['reason'] ) > 500 ) {
						return $this->error( 'rest_invalid_param', 400 );
					}
					$row = $store->recount( $row, strtolower( $request['id'] ), $counted, sanitize_textarea_field( $request['reason'] ) );
				}
				return new WP_REST_Response( $row );
			}
			if ( 'GET' === $request->get_method() ) {
				$args = $this->list_args( $request );
				if ( is_wp_error( $args ) ) {
					return $args;
				}
				if ( '/last' === substr( $route, -5 ) ) {
					if ( empty( $args['register_id'] ) ) {
						return $this->error( 'rest_invalid_param', 400 );
					}
					$args = array_merge(
						$args,
						array(
							'number_order' => true,
							'page' => 1,
							'per_page' => 1,
						)
					);
					return new WP_REST_Response( $store->list( $args )[0] ?? null );
				}
				return new WP_REST_Response( $store->list( $args ) );
			}
			if ( ! Pos_Uuid::is_uuid( $request['id'] ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			$id = strtolower( $request['id'] );
			$row = $store->get( $id );
			if ( $row ) {
				return new WP_REST_Response( $row );
			}
			$fields = $this->fields( $request );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$created = false;
			$row = $store->create( $fields, $created );
			return is_wp_error( $row ) ? $row : new WP_REST_Response( $row, $created ? 201 : 200 );
		} catch ( \RuntimeException $error ) {
			return $this->error( 'wcpos_closure_write_failed', 500 );
		}
	}

	/** Validate the immutable client fields, excluding server-owned columns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function fields( $request ) {
		if ( ! Pos_Uuid::is_uuid( $request['session_id'] ) || ! is_string( $request['software_version'] ) || Pos_Order_Audit::char_length( $request['software_version'] ) > 64 || ! is_array( $request['breakdowns'] ) ) {
			return $this->error( 'rest_invalid_param', 400 );
		}
		$fields = array(
			'id' => strtolower( $request['id'] ),
			'session_id' => strtolower( $request['session_id'] ),
			'software_version' => sanitize_text_field( $request['software_version'] ),
			'breakdowns' => $request['breakdowns'],
		);
		foreach ( array( 'number', 'unsynced_count', 'first_sale_counter', 'last_sale_counter' ) as $key ) {
			$value = $request[ $key ];
			$nullable = in_array( $key, array( 'first_sale_counter', 'last_sale_counter' ), true );
			if ( ! ( $nullable && null === $value ) && ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,18}$/D', (string) $value ) || ( 'number' === $key && $value < 1 ) || ( 'unsynced_count' === $key && $value > 2147483647 ) ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			$fields[ $key ] = null === $value ? null : (int) $value;
		}
		foreach ( array( 'opened_at', 'closed_at', 'printed_at' ) as $key ) {
			if ( 'printed_at' === $key && null === $request[ $key ] ) {
				$fields[ $key . '_gmt' ] = null;
				continue;
			}
			if ( ! is_string( $request[ $key ] ) || ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $request[ $key ] ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			$fields[ $key . '_gmt' ] = gmdate( 'Y-m-d H:i:s', strtotime( $request[ $key ] ) );
		}
		foreach ( array( 'till_expected', 'counted' ) as $key ) {
			$fields[ $key ] = $this->tenders( $request[ $key ], 'till_expected' === $key );
			if ( null === $fields[ $key ] ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
		}
		foreach ( array( 'period_sales_total', 'period_refunds_total', 'perpetual_sales_total', 'perpetual_refunds_total', 'unsynced_total' ) as $key ) {
			$fields[ $key ] = $this->decimal( $request[ $key ] );
			if ( null === $fields[ $key ] ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
		}
		return $fields;
	}

	/** Validate tender maps, allowing a negative till expectation.
	 *
	 * @param mixed $value Input map.
	 * @param bool  $signed Allow negative balances.
	 */
	private function tenders( $value, bool $signed = false ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['cash'] ) ) {
			return null;
		}
		foreach ( $value as $key => &$amount ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,191}$/D', $key ) ) {
				return null;
			}
			$amount = $this->decimal( $amount, $signed );
			if ( null === $amount ) {
				return null;
			}
		}
		return $value;
	}

	/** Preserve decimal precision and reject values outside storage range.
	 *
	 * @param mixed $value Decimal input.
	 * @param bool  $signed Allow negatives.
	 */
	private function decimal( $value, bool $signed = false ): ?string {
		if ( ! is_string( $value ) || ! preg_match( $signed ? '/^-?\d{1,15}(?:\.\d{1,4})?$/D' : '/^\d{1,15}(?:\.\d{1,4})?$/D', $value ) ) {
			return null;
		}
		return Closure_Store::sum( array( $value ) );
	}

	/** Validate list filters before the Pro scoping seam.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function list_args( $request ) {
		$args = array();
		foreach ( array( 'register_id', 'store_id', 'after', 'before', 'page', 'per_page' ) as $key ) {
			if ( ! $request->has_param( $key ) ) {
				continue;
			}
			$value = $request[ $key ];
			if ( in_array( $key, array( 'after', 'before' ), true ) ) {
				if ( ! is_string( $value ) || ! Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', $value ) ) {
					return $this->error( 'rest_invalid_param', 400 );
				}
				$value = gmdate( 'Y-m-d H:i:s', strtotime( $value ) );
			} elseif ( 'register_id' === $key ) {
				if ( ! Pos_Uuid::is_uuid( $value ) ) {
					return $this->error( 'rest_invalid_param', 400 );
				}
				$value = strtolower( $value );
			} elseif ( ! is_scalar( $value ) || ! preg_match( '/^[1-9]\d{0,17}$/D', (string) $value ) || ( 'per_page' === $key && $value > 100 ) ) {
				return $this->error( 'rest_invalid_param', 400 );
			}
			$args[ $key ] = $value;
		}
		return apply_filters( 'woocommerce_pos_closures_list_args', $args, $request );
	}

	/** Shared closure error envelope.
	 *
	 * @param string $code Error code.
	 * @param int    $status HTTP status.
	 */
	private function error( string $code, int $status ): WP_Error {
		return new WP_Error( $code, __( 'The closure request could not be completed.', 'woocommerce-pos' ), array( 'status' => $status ) );
	}
}
