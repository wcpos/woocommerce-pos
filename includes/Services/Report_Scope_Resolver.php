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
		$reach = Store_Reach::for_request( $request ?? new \WP_REST_Request( 'GET', '/wcpos/v2/reports' ) );
		if ( array() === $reach->store_ids() && ! $reach->admits( null ) ) {
			// A caller allowed neither stores nor unassigned rows can read no report.
			return new WP_Error( 'wcpos_report_not_found', __( 'Report not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		if ( 'session' === $args['mode'] ) {
			$session = ( new Register_Session_Store() )->get( $args['session_id'] );
			// Out of scope answers exactly as absent does, and before the closed check, so the
			// refusal cannot be read as "this session exists elsewhere, and it is still open".
			if ( ! $session || ! $reach->admits( $session['store_id'] ) ) {
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
		//
		// A session's own register is exempt from the *scope* half, because the session has
		// already been authorized by its stored store and a register may be reassigned to
		// another store afterwards (`Register_Store::update()` permits it). Checking the
		// register's current store would 404 a historical session report for a caller who is
		// plainly entitled to it — and would contradict the line below, which deliberately keeps
		// the session's retained store for exactly this reason. A session's register the repository
		// no longer holds (Pro deactivated) is not absent either: the session row is the provenance.
		if ( ! $session && null !== $register_id && ! $register ) {
			return new WP_Error( 'wcpos_report_register_not_found', __( 'Register not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		if ( ! $session && null !== $register_id && ! $reach->admits( $register['store_id'] ) ) {
			return new WP_Error( 'wcpos_report_register_not_found', __( 'Register not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		// A closed session retains its store even if its register has since moved.
		$store_id = $session ? (int) $session['store_id'] : (int) ( $register['store_id'] ?? $args['store_id'] ?? 0 );
		if ( ! $session && $register && isset( $args['store_id'] ) && $args['store_id'] !== $store_id ) {
			return self::mismatch();
		}
		// A store named outright is checked the same way a row's store is. A store nobody named
		// leaves $store_id at 0, and there is nothing to authorize: scoping the query is then the
		// report's own responsibility per the contract.
		if ( isset( $args['store_id'] ) && ! $reach->admits( $args['store_id'] ) ) {
			return new WP_Error( 'wcpos_report_not_found', __( 'Report not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$resolver = new Receipt_Store_Resolver( wcpos_get_store( $store_id ) );
		$timezone = $resolver->resolve_store_timezone();
		return array_replace(
			$args,
			array(
				'store_id' => $store_id,
				// Keep the requested register; resolve() fills a session's own.
				'register_id' => $args['register_id'] ?? null,
				// Authorising a historical session does not authorise reading its register's
				// *current* details. Once a register moves to a store this caller cannot reach,
				// its live name is out-of-scope metadata — and renaming it would otherwise leak
				// through every replay of the old session. Fall back to the label the closure
				// captured at the time, which is what the closure builder prints anyway.
				'register_name' => ( $session && ! $reach->admits( $register['store_id'] ?? null ) )
					? (string) ( $closure['breakdowns']['labels']['register_name'] ?? __( 'Register', 'woocommerce-pos' ) )
					: ( $register['name'] ?? ( null === $register_id ? '' : ( new Register_Store() )->display_name( $register_id ) ) ),
				'timezone' => $timezone->getName(),
				// `business_day` is nullable and is back-filled in batches by
				// Activator::upgrade_business_days(), so a legacy session can still be unstamped —
				// and the report schema requires a date-shaped value, so carrying the null through
				// would 500 that session's report. Derive it the way the migration does, from
				// `opened_at_gmt` in **wp_timezone()**, deliberately not the store's timezone:
				// matching the migration means the day a report shows does not change when the
				// back-fill finally lands. A stamped session always keeps its own stamp.
				'business_day' => $session
					? ( $session['business_day'] ?? ( new DateTimeImmutable( $session['opened_at_gmt'], new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' ) )
					: ( new DateTimeImmutable( 'today', $timezone ) )->format( 'Y-m-d' ),
				'allowed_store_ids' => $reach->store_ids(),
				'allowed_unassigned' => $reach->admits( null ),
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
		// The contract makes scoping the query the report's own responsibility, so the report has
		// to be told what the caller may reach. Without this a range request naming no store or
		// register leaves `store_id` at 0 and the producer has no way to honour a Pro scope it
		// cannot see — a duty assigned with no means of discharging it. Null means unrestricted.
		// `allowed_unassigned` says whether rows with no store (NULL or 0) are also within reach;
		// it is true when unrestricted.
		$scope['allowed_store_ids'] = $context['allowed_store_ids'];
		$scope['allowed_unassigned'] = $context['allowed_unassigned'];
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

	/** Conflicting identifiers cannot widen a report's scope. */
	private static function mismatch(): WP_Error {
		return new WP_Error( 'wcpos_report_scope_mismatch', __( 'The session, register and store must describe the same scope.', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}
}
