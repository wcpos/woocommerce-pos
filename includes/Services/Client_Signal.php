<?php
/**
 * WCPOS client protocol signal telemetry.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WP_REST_Request;

/** Reads and records the client protocol signal carried by REST requests. */
final class Client_Signal {
	/**
	 * Read a sanitized client signal from its header or query transport.
	 *
	 * @param WP_REST_Request $request Request to inspect.
	 * @return array{protocol: ?string, platform: ?string, app_version: ?string, channel: 'header'|'query'|'none'}
	 */
	public static function read( WP_REST_Request $request ): array {
		$params          = $request->get_query_params();
		$protocol_header = $request->get_header( 'X-WCPOS-Protocol' );
		$client_header   = $request->get_header( 'X-WCPOS-Client' );
		$has_header      = null !== $protocol_header || null !== $client_header;
		$has_query       = array_key_exists( 'wcpos_protocol', $params ) || array_key_exists( 'wcpos_client', $params );

		$protocol_raw = null !== $protocol_header ? $protocol_header : ( $params['wcpos_protocol'] ?? null );
		$client_raw   = null !== $client_header ? $client_header : ( $params['wcpos_client'] ?? null );
		$client       = self::sanitize_client( $client_raw );

		return array(
			'protocol'    => self::sanitize_protocol( $protocol_raw ),
			'platform'    => $client[0],
			'app_version' => $client[1],
			'channel'     => $has_header ? 'header' : ( $has_query ? 'query' : 'none' ),
		);
	}

	/**
	 * Record the signal once daily for each distinct signal tuple.
	 *
	 * @param WP_REST_Request $request Request to inspect.
	 */
	public static function record( WP_REST_Request $request ): void {
		$properties = self::read( $request );
		$dedup_key  = 'pos_client_signal:' . md5( wp_json_encode( $properties ) );
		Analytics::instance()->capture_once( 'pos_client_signal', $properties, $dedup_key );
	}

	/**
	 * Sanitize a protocol value to at most eight digits.
	 *
	 * @param mixed $value Raw protocol value.
	 */
	private static function sanitize_protocol( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = substr( (string) preg_replace( '/[^0-9]/', '', (string) $value ), 0, 8 );
		return '' === $value ? null : $value;
	}

	/**
	 * Split and sanitize a client value.
	 *
	 * @param mixed $value Raw client value.
	 * @return array{0: ?string, 1: ?string} Sanitized client parts.
	 */
	private static function sanitize_client( $value ): array {
		if ( ! is_scalar( $value ) ) {
			return array( null, null );
		}

		$value = (string) $value;
		if ( false === strpos( $value, '/' ) ) {
			return array( self::sanitize_client_part( $value ), null );
		}

		$parts       = explode( '/', $value, 2 );
		$platform    = self::sanitize_client_part( $parts[0] );
		$app_version = self::sanitize_client_part( $parts[1] );
		if ( null === $platform || null === $app_version ) {
			return array( self::sanitize_client_part( $value ), null );
		}

		return array( $platform, $app_version );
	}

	/**
	 * Sanitize one client component to its 32-character wire shape.
	 *
	 * @param string $value Raw client component.
	 */
	private static function sanitize_client_part( string $value ): ?string {
		$value = substr( (string) preg_replace( '/[^a-z0-9._-]/', '', strtolower( $value ) ), 0, 32 );
		return '' === $value ? null : $value;
	}
}
