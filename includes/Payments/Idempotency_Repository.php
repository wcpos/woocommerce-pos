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
	 * Build transient key.
	 *
	 * @param string $scope Scope name.
	 * @param string $key   Idempotency key.
	 */
	private function transient_key( string $scope, string $key ): string {
		return 'wcpos_idempotency_' . md5( $scope . '|' . $key );
	}
}
