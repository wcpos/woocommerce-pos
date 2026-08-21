<?php
/**
 * Core-route POS audit-meta guard.
 *
 * WCPOS Bearer tokens authenticate every REST request (Init hooks
 * determine_current_user globally, without requiring the X-WCPOS marker), so a
 * cashier whose capabilities allow order writes could bypass the
 * server-authoritative `_pos_*` audit enforcement on the wcpos namespaces by
 * writing the same keys through core routes such as `wc/v3/orders` or its
 * batch endpoint. This guard rejects those writes.
 *
 * It MUST be registered unconditionally from Init (alongside
 * determine_current_user_early), never from the X-WCPOS-gated API class — an
 * attacker controls whether that marker is sent.
 *
 * Cookie- and application-password-authenticated requests are intentionally
 * not guarded: a user whose capabilities allow editing order meta through
 * wp-admin or core REST keeps that power. The guard only closes the gap where
 * a POS token grants more than the POS surface would allow.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Sync\Meta_Entry;
use WP_Error;
use WP_REST_Request;
use WCPOS\WooCommercePOS\API;

/**
 * Core_Order_Audit_Guard service.
 */
final class Core_Order_Audit_Guard {
	/**
	 * User authenticated before WCPOS's priority-20 JWT filter.
	 *
	 * @var int
	 */
	private $pre_wcpos_user_id = 0;

	/**
	 * Register the guard on the REST dispatch pipeline.
	 */
	public function register_hooks(): void {
		add_filter( 'determine_current_user', array( $this, 'record_prior_authentication' ), 20 );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );
	}

	/**
	 * Record authentication completed before WCPOS's priority-20 JWT filter.
	 *
	 * @param false|int|WP_Error $user_id User ID if already authenticated, false or error otherwise.
	 *
	 * @return false|int|WP_Error
	 */
	public function record_prior_authentication( $user_id ) {
		$this->pre_wcpos_user_id = \is_numeric( $user_id ) ? absint( $user_id ) : 0;

		return $user_id;
	}

	/**
	 * Reject non-wcpos REST writes of POS audit meta by WCPOS-JWT-authenticated requests.
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param \WP_REST_Server $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return mixed
	 */
	public function rest_pre_dispatch( $result, $server, $request ) {
		$error                   = $this->guard( $request );
		$this->pre_wcpos_user_id = 0;

		return is_wp_error( $error ) ? $error : $result;
	}

	/**
	 * Evaluate one request. Cheap checks run first: no token cryptography and no
	 * order loads unless the payload actually carries suspicious meta entries.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return null|WP_Error WP_Error to short-circuit the dispatch, null to pass through.
	 */
	private function guard( WP_REST_Request $request ) {
		if ( ! \in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return null;
		}

		// WordPress matches REST routes case-insensitively, so every route
		// comparison below must be case-insensitive too.
		$route = $request->get_route();
		if ( $this->is_wcpos_route( $route ) ) {
			// The wcpos controllers enforce server-authoritative audit meta themselves.
			return null;
		}
		if ( ! preg_match( '#/orders(?:/\d+|/batch)?/?$#i', $route ) ) {
			return null;
		}

		$is_order_batch = (bool) preg_match( '#/orders/batch/?$#i', $route );

		// An order batch beyond WooCommerce's own limit is rejected by
		// check_batch_limit() with a 413 before any item is written, so skip
		// scanning it rather than doing unbounded work here.
		if ( $is_order_batch && $this->batch_over_limit( $request ) ) {
			return null;
		}

		// Candidate meta_data sets: top-level, plus per-item for batch requests.
		// Each candidate is array{ 0: mixed meta_data, 1: int order_id }.
		$single_order_id = 0;
		if ( preg_match( '#/orders/(\d+)/?$#i', $route, $matches ) ) {
			$single_order_id = (int) $matches[1];
		}

		$candidates = array( array( $request->get_param( 'meta_data' ), $single_order_id ) );
		foreach ( array( 'create', 'update' ) as $operation ) {
			foreach ( (array) $request->get_param( $operation ) as $item ) {
				$item     = (array) $item;
				$order_id = ( $is_order_batch && 'update' === $operation ) ? (int) ( $item['id'] ?? 0 ) : 0;
				if ( isset( $item['meta_data'] ) ) {
					$candidates[] = array( $item['meta_data'], $order_id );
				}
			}
		}

		// Pass 1 — audit keys by name. No auth or order lookup needed to detect,
		// and only a request that is actually suspicious pays for token validation.
		$audit_keys    = Pos_Order_Audit::audit_meta_keys();
		$has_audit_key = false;
		foreach ( $candidates as $candidate ) {
			foreach ( $this->meta_entries( $candidate[0] ) as $entry ) {
				if ( \in_array( Meta_Entry::key( $entry ), $audit_keys, true ) ) {
					$has_audit_key = true;
					break 2;
				}
			}
		}

		if ( $has_audit_key ) {
			return $this->is_wcpos_jwt_authenticated() ? $this->forbidden() : null;
		}

		// Pass 2 — id-addressed entries. WooCommerce resolves a meta_data entry by
		// its numeric `id` BEFORE its `key` and overwrites the row, so an entry
		// under a harmless key can rename an audit row away. Only order-targeted
		// candidates can be checked, and each order is loaded at most once, only
		// for JWT-authenticated requests with an id-addressed entry present.
		$ids_by_order = array();
		foreach ( $candidates as $candidate ) {
			if ( $candidate[1] <= 0 ) {
				continue;
			}
			foreach ( $this->meta_entries( $candidate[0] ) as $entry ) {
				if ( is_numeric( $entry['id'] ?? null ) ) {
					$ids_by_order[ $candidate[1] ][] = (int) $entry['id'];
				}
			}
		}

		if ( array() === $ids_by_order || ! $this->is_wcpos_jwt_authenticated() ) {
			return null;
		}

		foreach ( $ids_by_order as $order_id => $meta_ids ) {
			$protected = Pos_Order_Audit::audit_meta_ids( wc_get_order( $order_id ) );
			if ( array() !== array_intersect( $meta_ids, $protected ) ) {
				return $this->forbidden();
			}
		}

		return null;
	}

	/**
	 * The rejection response.
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error(
			'woocommerce_pos_rest_audit_meta_forbidden',
			__( 'POS audit metadata cannot be modified through this REST route.', 'woocommerce-pos' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether the route belongs to a WCPOS REST namespace (case-insensitive,
	 * mirroring WordPress route matching). Same namespace list as
	 * API::get_route_namespaces(), which is not static and lives on the
	 * X-WCPOS-gated API class this guard must not depend on being constructed.
	 *
	 * @param string $route REST route, e.g. `/wc/v3/orders`.
	 *
	 * @return bool
	 */
	private function is_wcpos_route( string $route ): bool {
		/** This filter is documented in includes/API.php */
		$namespaces = apply_filters( 'woocommerce_pos_rest_namespaces', API::ROUTE_NAMESPACES );
		$namespaces = array_unique( array_merge( API::ROUTE_NAMESPACES, (array) $namespaces ) );

		foreach ( $namespaces as $namespace ) {
			if ( \is_string( $namespace ) && '' !== $namespace && 0 === stripos( trailingslashit( $route ), '/' . trailingslashit( $namespace ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an order batch exceeds WooCommerce's own batch limit.
	 *
	 * Mirrors WC_REST_Controller::check_batch_limit(), which rejects the whole
	 * request with a 413 before processing any item.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return bool
	 */
	private function batch_over_limit( WP_REST_Request $request ): bool {
		$total = 0;
		foreach ( array( 'create', 'update', 'delete' ) as $operation ) {
			$items  = $request->get_param( $operation );
			$total += \is_array( $items ) ? \count( $items ) : 0;
		}

		/** This filter is documented in WooCommerce's WC_REST_Controller::check_batch_limit() */
		$limit = (int) apply_filters( 'woocommerce_rest_batch_items_limit', 100, 'orders' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.

		return $total > $limit;
	}

	/**
	 * Normalize a request meta_data value into an array of entry arrays.
	 *
	 * @param mixed $meta_data Raw meta_data parameter (array of arrays/objects, or anything else).
	 *
	 * @return array<int, array>
	 */
	private function meta_entries( $meta_data ): array {
		if ( ! \is_array( $meta_data ) ) {
			return array();
		}

		return array_map(
			static function ( $entry ) {
				return (array) $entry;
			},
			$meta_data
		);
	}

	/**
	 * Whether the current request was authenticated by a WCPOS token.
	 *
	 * Authentication filters before WCPOS's priority-20 filter retain provenance
	 * for cookie and other credentials. If determine_current_user was skipped
	 * because the user was already loaded, that also represents prior auth.
	 * Otherwise, re-validate the token and require it to resolve to the
	 * current user.
	 *
	 * @return bool
	 */
	private function is_wcpos_jwt_authenticated(): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( $this->pre_wcpos_user_id > 0 ) {
			return false;
		}

		$auth_header = $this->get_auth_header();
		if ( ! \is_string( $auth_header ) || '' === $auth_header ) {
			return false;
		}

		$auth_service = Auth::instance();
		$token        = $auth_service->extract_token( $auth_header );
		if ( null === $token ) {
			return false;
		}

		$decoded = $auth_service->validate_token( $token );
		if ( is_wp_error( $decoded ) ) {
			return false;
		}

		return absint( $decoded->data->user->id ) === $user_id;
	}

	/**
	 * Extract the Authorization credential from the request environment.
	 *
	 * Same three sources as Init::get_auth_header_early() (private there);
	 * candidates for consolidation into the Auth service.
	 *
	 * @return false|string
	 */
	private function get_auth_header() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( ! empty( $_GET['authorization'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( $_GET['authorization'] ) );
		}

		return false;
	}
}
