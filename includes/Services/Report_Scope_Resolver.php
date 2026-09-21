<?php
/**
 * Resolve report identity and store-local time boundaries.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeImmutable;
use DateTimeZone;
use WCPOS\WooCommercePOS\Logger;
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
		if ( array() === $allowed ) {
			// A caller allowed no stores at all can read no report.
			return new WP_Error( 'wcpos_report_not_found', __( 'Report not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		if ( 'session' === $args['mode'] ) {
			$session = ( new Register_Session_Store() )->get( $args['session_id'] );
			// Out of scope answers exactly as absent does, and before the closed check, so the
			// refusal cannot be read as "this session exists elsewhere, and it is still open".
			if ( ! $session || ! self::row_in_scope( $session['store_id'], $allowed ) ) {
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
		// the session's retained store for exactly this reason.
		if ( null !== $register_id && ! $register ) {
			return new WP_Error( 'wcpos_report_register_not_found', __( 'Register not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		if ( ! $session && null !== $register_id && ! self::row_in_scope( $register['store_id'], $allowed ) ) {
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
		if ( isset( $args['store_id'] ) && ! self::row_in_scope( $args['store_id'], $allowed ) ) {
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
				// Authorising a historical session does not authorise reading its register's
				// *current* details. Once a register moves to a store this caller cannot reach,
				// its live name is out-of-scope metadata — and renaming it would otherwise leak
				// through every replay of the old session. Fall back to the label the closure
				// captured at the time, which is what the closure builder prints anyway.
				'register_name' => ( $session && ! self::row_in_scope( $register['store_id'] ?? null, $allowed ) )
					? (string) ( $closure['breakdowns']['labels']['register_name'] ?? '' )
					: ( $register['name'] ?? '' ),
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
				'allowed_store_ids' => $allowed,
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
		$scope['allowed_store_ids'] = $context['allowed_store_ids'];
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
		if ( ! \is_array( $args ) ) {
			// A filter that answers with something other than an array has malfunctioned, and we
			// cannot know what this caller may reach. Deny — `array()` is the allow-nothing scope
			// the caller refuses on. Returning null here would read as "unrestricted" and hand
			// every store out at precisely the moment something is wrong: the safe-looking
			// fallback is the unsafe one.
			Logger::warning(
				'Report scope refused: woocommerce_pos_closures_list_args did not return an array',
				array( 'type' => \gettype( $args ) )
			);
			return array();
		}
		// An array without a store_id key is the ordinary unrestricted case: no Pro plugin is
		// scoping this caller. That is not the same as a malformed answer.
		if ( ! array_key_exists( 'store_id', $args ) ) {
			return null;
		}
		// The values have to be validated before coercion, not after. `intval()` turns `false`,
		// `null` or `'invalid'` into 0 — and `row_in_scope()` turns a row's NULL store into 0 too,
		// so a malformed restriction would match exactly the unassigned rows the restriction is
		// there to protect. A restriction we cannot read is a restriction we must not guess at.
		$ids = array();
		foreach ( (array) $args['store_id'] as $id ) {
			// Deliberately narrower than is_numeric(), which accepts shapes that then *coerce* into
			// a different, plausible-looking restriction rather than failing: 1.9 and '1.9' and
			// ' 1' all become store 1, '1e2' becomes store 100, '007' becomes store 7. Silently
			// turning an unreadable restriction into a readable one is the same guessing this
			// guard exists to prevent — only harder to notice, because the result looks valid.
			$well_formed = ( \is_int( $id ) && $id > 0 )
				|| ( \is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) );
			if ( ! $well_formed ) {
				Logger::warning(
					'Report scope refused: woocommerce_pos_closures_list_args gave an unreadable store restriction',
					array( 'type' => \gettype( $id ) )
				);
				return array();
			}
			$ids[] = (int) $id;
		}
		return $ids;
	}

	/**
	 * Whether an addressed resource's store is within the caller's scope.
	 *
	 * Membership is required whenever a scope is in force, with **no exemption for 0**. The
	 * `store_id` columns on the sessions, registers and closures tables are all `BIGINT NULL`, so
	 * an unassigned row casts to 0; treating 0 as "nothing was named" would let a scoped caller
	 * read any unassigned register or session. `Fiscal_Record_Store::resolve_document()` refuses
	 * the same row, and this must not be laxer than the read it sits beside.
	 *
	 * Every refusal built on this answers **404, not 403**: out of scope reads as absent, as it
	 * does on the records and closures reads, so a scoped manager cannot probe what exists
	 * elsewhere. That is the opposite of `Report_Scope_Gate`'s 403, which is a tier boundary the
	 * merchant is meant to see and the app turns into a *See Pro* button.
	 *
	 * @param mixed      $store_id The row's store, possibly null.
	 * @param int[]|null $allowed  Allowed stores, or null when unrestricted.
	 */
	private static function row_in_scope( $store_id, ?array $allowed ): bool {
		return null === $allowed || \in_array( (int) $store_id, $allowed, true );
	}

	/** Conflicting identifiers cannot widen a report's scope. */
	private static function mismatch(): WP_Error {
		return new WP_Error( 'wcpos_report_scope_mismatch', __( 'The session, register and store must describe the same scope.', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}
}
