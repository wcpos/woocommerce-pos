<?php
/**
 * POS idempotency repository.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

use WP_Error;

/**
 * Repository for idempotent request replay.
 *
 * Stores payloads in transients with a 24 hour TTL.
 */
class Idempotency_Repository {
	/**
	 * TTL in seconds.
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * Maximum age for an in-flight claim before it is considered stale.
	 */
	private const CLAIM_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Find an existing idempotent response.
	 *
	 * @param string $scope Scope name.
	 * @param string $key   Idempotency key.
	 */
	public function find( string $scope, string $key ): ?array {
		$value = get_transient( $this->transient_key( $scope, $key ) );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store an idempotent response payload.
	 *
	 * @param string $scope        Scope name.
	 * @param string $key          Idempotency key.
	 * @param string $request_hash Request hash.
	 * @param int    $status_code  HTTP status code.
	 * @param array  $body         Response body.
	 */
	public function store( string $scope, string $key, string $request_hash, int $status_code, array $body ): void {
		set_transient(
			$this->transient_key( $scope, $key ),
			array(
				'request_hash' => $request_hash,
				'status_code'  => $status_code,
				'body'         => $body,
			),
			self::TTL
		);
	}

	/**
	 * Claim an idempotency key for in-flight processing.
	 *
	 * Returns an existing response payload when the request has already been
	 * completed, true when the caller successfully claimed the key, and a
	 * WP_Error when the key conflicts or is currently being processed.
	 *
	 * @param string $scope        Scope name.
	 * @param string $key          Idempotency key.
	 * @param string $request_hash Request hash.
	 *
	 * @return true|array|WP_Error
	 */
	public function claim( string $scope, string $key, string $request_hash ) {
		$existing = $this->find( $scope, $key );

		if ( $existing ) {
			if ( $existing['request_hash'] !== $request_hash ) {
				return new WP_Error(
					'wcpos_idempotency_conflict',
					__( 'Idempotency key was reused with a different request.', 'woocommerce-pos' ),
					array( 'status' => 409 )
				);
			}

			return $existing;
		}

		$claim_key = $this->claim_key( $scope, $key );
		$claim     = array(
			'request_hash' => $request_hash,
			'claimed_at'   => time(),
		);

		if ( $this->try_claim_option( $claim_key, $claim ) ) {
			return true;
		}

		$current_claim = get_option( $claim_key );
		if ( $this->is_stale_claim( $current_claim ) ) {
			delete_option( $claim_key );
			$current_claim = get_option( $claim_key );
		}

		if ( false === $current_claim ) {
			// @phpstan-ignore-next-line Option creation success depends on live database state.
			if ( $this->try_claim_option( $claim_key, $claim ) ) {
				return true;
			}

			$current_claim = get_option( $claim_key );
		}

		$existing = $this->find( $scope, $key );
		if ( $existing ) {
			if ( $existing['request_hash'] !== $request_hash ) {
				return new WP_Error(
					'wcpos_idempotency_conflict',
					__( 'Idempotency key was reused with a different request.', 'woocommerce-pos' ),
					array( 'status' => 409 )
				);
			}

			return $existing;
		}

		if ( is_array( $current_claim ) && ( $current_claim['request_hash'] ?? null ) !== $request_hash ) {
			return new WP_Error(
				'wcpos_idempotency_conflict',
				__( 'Idempotency key was reused with a different request.', 'woocommerce-pos' ),
				array( 'status' => 409 )
			);
		}

		return new WP_Error(
			'wcpos_idempotency_in_progress',
			__( 'An identical request is already being processed.', 'woocommerce-pos' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Reject conflicting reuse of an idempotency key.
	 *
	 * @param string $scope        Scope name.
	 * @param string $key          Idempotency key.
	 * @param string $request_hash Request hash.
	 *
	 * @return true|WP_Error
	 */
	public function assert_not_conflicting( string $scope, string $key, string $request_hash ) {
		$existing = $this->find( $scope, $key );

		if ( $existing && $existing['request_hash'] !== $request_hash ) {
			return new WP_Error(
				'wcpos_idempotency_conflict',
				__( 'Idempotency key was reused with a different request.', 'woocommerce-pos' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Release an in-flight claim without storing a replayable response.
	 *
	 * @param string $scope Scope name.
	 * @param string $key   Idempotency key.
	 */
	public function release( string $scope, string $key ): void {
		delete_option( $this->claim_key( $scope, $key ) );
	}

	/**
	 * Build transient key.
	 *
	 * @param string $scope Scope name.
	 * @param string $key   Idempotency key.
	 */
	private function transient_key( string $scope, string $key ): string {
		return 'wcpos_idempotency_' . md5( $scope . '|' . $key );
	}

	/**
	 * Build in-flight claim option key.
	 *
	 * @param string $scope Scope name.
	 * @param string $key   Idempotency key.
	 */
	private function claim_key( string $scope, string $key ): string {
		return 'wcpos_idempotency_claim_' . md5( $scope . '|' . $key );
	}

	/**
	 * Whether the stored claim is stale.
	 *
	 * @param mixed $claim Claim value.
	 */
	private function is_stale_claim( $claim ): bool {
		if ( ! is_array( $claim ) ) {
			return false;
		}

		$claimed_at = isset( $claim['claimed_at'] ) ? (int) $claim['claimed_at'] : 0;

		return $claimed_at > 0 && ( time() - $claimed_at ) >= self::CLAIM_TTL;
	}

	/**
	 * Attempt to atomically claim the option row for this key.
	 *
	 * @param string $claim_key Claim option key.
	 * @param array  $claim     Claim payload.
	 */
	private function try_claim_option( string $claim_key, array $claim ): bool {
		// @phpstan-ignore-next-line WordPress option writes are runtime-dependent.
		return add_option( $claim_key, $claim, '', false );
	}
}
