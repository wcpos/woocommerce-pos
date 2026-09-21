<?php
/**
 * Resolve report identity and store-local time boundaries.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

/** Loads stored scope facts before the gate; builds query bounds only after it. */
final class Report_Scope_Resolver {

	/**
	 * Read the identity needed by the scope gate from validated parameters.
	 *
	 * @param array                 $args Validated report parameters.
	 * @param \WP_REST_Request|null $request Request, for the store-scope seam.
	 * @return array|WP_Error
	 */
	public static function context( array $args, ?\WP_REST_Request $request = null ) {
		$session = null;
		$closure = null;
		$register_id = $args['register_id'] ?? null;
		$allowed = self::allowed_store_ids( $request );
		if ( 'session' === $args['mode'] ) {
			$session = ( new Register_Session_Store() )->get( $args['session_id'] );
			// Out of scope answers exactly as absent does, and before the closed check, so the
			// refusal cannot be read as "this session exists elsewhere, and it is still open".
			if ( ! $session || ! self::in_scope( (int) $session['store_id'], $allowed ) ) {
				return new WP_Error( 'wcpos_report_session_not_found', __( 'Session not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
			}
			if ( empty( $session['closure_id'] ) || empty( $session['closed_at_gmt'] ) ) {
				return new WP_Error( 'wcpos_report_session_not_closed', __( 'This report runs on a closed session.', 'woocommerce-pos' ), array( 'status' => 400 ) );
			}
			if ( ( null !== $register_id && $register_id !== $session['register_id'] ) || ( isset( $args['store_id'] ) && $args['store_id'] !== (int) $session['store_id'] ) ) {
				return self::mismatch();
			}
			$register_id = $session['register_id'];
			$closure = ( new Closure_Store() )->get( $session['closure_id'] );
			if ( ! $closure || $closure['session_id'] !== $session['id'] ) {
				return new WP_Error( 'wcpos_report_session_not_closed', __( 'The closed session must have a linked closure.', 'woocommerce-pos' ), array( 'status' => 400 ) );
			}
		}
		$register = null === $register_id ? null : ( new Register_Store() )->get( $register_id );
		// Same reasoning: a register in a store the caller cannot reach is simply absent, and
		// answers with the code a non-existent one does, so neither can be told from the other.
		if ( null !== $register_id && ( ! $register || ! self::in_scope( (int) $register['store_id'], $allowed ) ) ) {
			return new WP_Error( 'wcpos_report_register_not_found', __( 'Register not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		// A closed session retains its store even if its register has since moved.
		$store_id = $session ? (int) $session['store_id'] : (int) ( $register['store_id'] ?? $args['store_id'] ?? 0 );
		if ( ! $session && $register && isset( $args['store_id'] ) && $args['store_id'] !== $store_id ) {
			return self::mismatch();
		}
		if ( ! self::in_scope( $store_id, $allowed ) ) {
			return new WP_Error( 'wcpos_report_not_found', __( 'Report not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$resolver = new Receipt_Store_Resolver( wcpos_get_store( $store_id ) );
		$timezone = $resolver->resolve_store_timezone();
		return array_replace(
			$args,
			array(
				'store_id' => $store_id,
				// Keep the requested register until the gate: a session must not fill a missing one for Free.
				'register_id' => $args['register_id'] ?? null,
				'register_name' => $register['name'] ?? '',
				'timezone' => $timezone->getName(),
				'business_day' => $session ? $session['business_day'] : ( new DateTimeImmutable( 'today', $timezone ) )->format( 'Y-m-d' ),
				'session' => $session,
				'closure' => $closure,
			)
		);
	}

	/**
	 * Produce the callable's scope, excluding request data and internal stored rows.
	 *
	 * @param array $context Authorized context from context().
	 */
	public static function resolve( array $context ): array {
		$scope = array_intersect_key( $context, array_flip( array( 'mode', 'store_id', 'register_id', 'register_name', 'business_day', 'timezone' ) ) );
		$scope['group_by'] = $context['group_by'] ?? null;
		if ( 'session' === $scope['mode'] ) {
			$session = $context['session'];
			$scope['register_id'] = $session['register_id'];
			return $scope + array(
				'session_id' => $session['id'],
				'session_number' => (int) $context['closure']['number'],
				'opened_at' => $session['opened_at_gmt'],
				'closed_at' => $session['closed_at_gmt'],
			);
		}
		$timezone = new DateTimeZone( $scope['timezone'] );
		$utc = new DateTimeZone( 'UTC' );
		$from = new DateTimeImmutable( $context['from'] . ' 00:00:00', $timezone );
		$to = ( new DateTimeImmutable( $context['to'] . ' 00:00:00', $timezone ) )->modify( '+1 day' );
		return $scope + array(
			'from' => $context['from'],
			'to' => $context['to'],
			'from_utc' => $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to_utc' => $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * The stores this caller may reach, or null when unrestricted.
	 *
	 * `woocommerce_pos_closures_list_args` reads like a filter for list arguments, but called
	 * with an **empty base** it is the store-authorization seam: Pro answers it with the stores
	 * the caller may reach, and `Fiscal_Record_Store::resolve_document()` uses it exactly this
	 * way before handing back a closure or X-report. Without consulting it, a manager scoped to
	 * one store could name another store's `session_id` or `register_id` and receive a document
	 * whose scope carries that store's register name and closure number.
	 *
	 * @param \WP_REST_Request|null $request Request passed to the seam.
	 * @return int[]|null
	 */
	private static function allowed_store_ids( ?\WP_REST_Request $request ): ?array {
		$args = apply_filters(
			'woocommerce_pos_closures_list_args',
			array(),
			$request ?? new \WP_REST_Request( 'GET', '/wcpos/v2/reports' )
		);
		if ( ! \is_array( $args ) || ! array_key_exists( 'store_id', $args ) ) {
			return null;
		}
		return array_map( 'intval', (array) $args['store_id'] );
	}

	/**
	 * Whether a store is within the caller's scope.
	 *
	 * Every refusal built on this answers **404, not 403**: out of scope reads as absent, as it
	 * does on the records and closures reads, so a scoped manager cannot probe what exists
	 * elsewhere. That is the opposite of `Report_Scope_Gate`'s 403, which is a tier boundary the
	 * merchant is meant to see and the app turns into a *See Pro* button.
	 *
	 * A store of 0 means none was named — no session, register or `store_id` — so there is no
	 * specific store to authorize, and scoping the query is the report's own responsibility per
	 * the contract.
	 *
	 * @param int        $store_id Store to test, or 0 when none was named.
	 * @param int[]|null $allowed  Allowed stores, or null when unrestricted.
	 */
	private static function in_scope( int $store_id, ?array $allowed ): bool {
		return null === $allowed || $store_id <= 0 || \in_array( $store_id, $allowed, true );
	}

	/** Conflicting identifiers cannot widen a report's scope. */
	private static function mismatch(): WP_Error {
		return new WP_Error( 'wcpos_report_scope_mismatch', __( 'The session, register and store must describe the same scope.', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}
}
