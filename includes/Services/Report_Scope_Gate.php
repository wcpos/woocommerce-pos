<?php
/**
 * Edition and history reach boundary for server reports.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

/** Free queries stay on today's explicitly selected register. */
final class Report_Scope_Gate {

	/**
	 * Check validated scope facts before any producer runs.
	 *
	 * @param array     $scope Scope context with the store-resolved timezone.
	 * @param bool|null $pro Edition override; null detects the active edition.
	 * @return true|WP_Error
	 */
	public static function check( array $scope, ?bool $pro = null ) {
		$pro = $pro ?? ( function_exists( 'wcpos_is_pro_active' ) && wcpos_is_pro_active() );
		$today = new DateTimeImmutable( 'today', new DateTimeZone( $scope['timezone'] ) );
		$day = $today->format( 'Y-m-d' );
		if ( 'range' === $scope['mode'] ) {
			if ( $scope['to'] < $scope['from'] ) {
				return new WP_Error( 'rest_invalid_param', __( 'The end day must not precede the start day.', 'woocommerce-pos' ), array( 'status' => 400 ) );
			}
			if ( $scope['from'] < $today->modify( '-' . Reports_Registry::HISTORY_DAYS . ' days' )->format( 'Y-m-d' ) ) {
				return self::locked( __( 'This range is beyond the report history limit.', 'woocommerce-pos' ) );
			}
		}
		if ( ! $pro ) {
			if ( empty( $scope['register_id'] ) ) {
				return self::locked( __( 'Reports across all registers require WCPOS Pro. Select this register.', 'woocommerce-pos' ) );
			}
			$is_today = 'range' === $scope['mode'] ? $scope['from'] === $day && $scope['to'] === $day : $scope['business_day'] === $day;
			if ( ! $is_today ) {
				return self::locked( __( 'Reports for earlier days or days other than today require WCPOS Pro.', 'woocommerce-pos' ) );
			}
		}
		return true;
	}

	/**
	 * A visible edition boundary, not a concealed record lookup.
	 *
	 * @param string $message Locked scope explanation.
	 */
	private static function locked( string $message ): WP_Error {
		return new WP_Error( 'wcpos_report_scope_locked', $message, array( 'status' => 403 ) );
	}
}
