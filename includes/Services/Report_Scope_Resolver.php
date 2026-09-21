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
	 * @param array $args Validated report parameters.
	 * @return array|WP_Error
	 */
	public static function context( array $args ) {
		$session = null;
		$closure = null;
		$register_id = $args['register_id'] ?? null;
		if ( 'session' === $args['mode'] ) {
			$session = ( new Register_Session_Store() )->get( $args['session_id'] );
			if ( ! $session ) {
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
		if ( null !== $register_id && ! $register ) {
			return new WP_Error( 'wcpos_report_register_not_found', __( 'Register not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		// A closed session retains its store even if its register has since moved.
		$store_id = $session ? (int) $session['store_id'] : (int) ( $register['store_id'] ?? $args['store_id'] ?? 0 );
		if ( ! $session && $register && isset( $args['store_id'] ) && $args['store_id'] !== $store_id ) {
			return self::mismatch();
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

	/** Conflicting identifiers cannot widen a report's scope. */
	private static function mismatch(): WP_Error {
		return new WP_Error( 'wcpos_report_scope_mismatch', __( 'The session, register and store must describe the same scope.', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}
}
