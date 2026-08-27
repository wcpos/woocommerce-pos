<?php
/**
 * Public request-header echo probe REST API controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Sync\Cors;
use WP_REST_Request;
use WP_REST_Response;

/** Reports which POS client headers reached the normal REST stack. */
final class Echo_Probe {
	/** Register the public echo probe route. */
	public function register_routes(): void {
		register_rest_route(
			'wcpos/v2',
			'/echo',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_echo' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Classify the probe as public for the central permission gate.
	 *
	 * @return array<string, string[]>
	 */
	public function wcpos_route_classifications(): array {
		return array( 'public' => array( '/wcpos/v2/echo' ) );
	}

	/**
	 * Return header and fallback-parameter presence without exposing values.
	 *
	 * @param WP_REST_Request $request Request to inspect.
	 */
	public function get_echo( WP_REST_Request $request ): WP_REST_Response {
		$headers = array();

		foreach ( array_merge( array( 'Authorization', 'Content-Type', 'X-WCPOS' ), Cors::headers() ) as $name ) {
			$key = strtolower( $name );
			// get_header() returns null when absent — normalize, or an absent
			// header reads as received (the exact case this probe detects).
			$value = (string) ( $request->get_header( $name ) ?? '' );

			if ( 'authorization' === $key && '' === $value && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && \is_string( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$value = $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // phpcs:ignore -- Raw byte length only; the value is never returned.
			}

			$headers[ $key ] = array(
				'received' => '' !== $value,
				'length'   => \strlen( $value ),
			);
		}
		$params = $request->get_query_params();

		return new WP_REST_Response(
			array(
				// Stays 1: shipped clients hard-gate on `v === 1` and read a
				// mismatch as "not the echo route" (hydration-steps.ts), so an
				// additive field must not bump it.
				'v'       => 1,
				'headers' => $headers,
				'params'  => array(
					'authorization' => isset( $params['authorization'] ) && '' !== $params['authorization'],
					'wcpos'         => isset( $params['wcpos'] ) && '' !== $params['wcpos'],
					'store_id'      => isset( $params['store_id'] ) && '' !== $params['store_id'],
					'wcpos_protocol' => isset( $params['wcpos_protocol'] ) && '' !== $params['wcpos_protocol'],
					'wcpos_client'   => isset( $params['wcpos_client'] ) && '' !== $params['wcpos_client'],
				),
				// Means: this SERVER reflects announced x-wcpos-* names at
				// preflight ({@see \WCPOS\WooCommercePOS\Rest_Cors}). It does
				// NOT prove this store's preflights reach PHP — an edge that
				// answers OPTIONS itself still blocks new headers, so a client
				// must confirm the path with one cross-origin request carrying
				// a throwaway x-wcpos-* header before trusting header transport.
				'cors'    => array( 'reflects_request_headers' => true ),
			),
			200
		);
	}
}
