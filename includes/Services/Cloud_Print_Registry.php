<?php
/**
 * Cloud printer registry (reads woocommerce_pos_settings_cloud_print).
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Cloud_Print_Registry class.
 */
class Cloud_Print_Registry {
	const OPTION = 'woocommerce_pos_settings_cloud_print';

	/**
	 * Get a registered cloud printer by id.
	 *
	 * @param string $printer_id Printer id.
	 *
	 * @return array|null
	 */
	public function get_printer( string $printer_id ): ?array {
		$settings = get_option( self::OPTION, array() );
		$printers = isset( $settings['printers'] ) && \is_array( $settings['printers'] ) ? $settings['printers'] : array();

		foreach ( $printers as $printer ) {
			if ( isset( $printer['id'] ) && hash_equals( (string) $printer['id'], $printer_id ) ) {
				return $printer;
			}
		}

		return null;
	}

	/**
	 * Verify a printer's poll token (constant-time).
	 *
	 * @param string $printer_id Printer id.
	 * @param string $token      Presented token.
	 */
	public function verify_token( string $printer_id, string $token ): bool {
		$printer = $this->get_printer( $printer_id );
		if ( null === $printer || empty( $printer['poll_token_hash'] ) || '' === $token ) {
			return false;
		}

		return hash_equals( (string) $printer['poll_token_hash'], self::hash_token( $token ) );
	}

	/**
	 * Generate a cryptographically strong poll token (returned to the admin once).
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Hash a poll token for at-rest storage (we never persist the plaintext).
	 *
	 * @param string $token Token.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Derive a stable, URL-safe printer id from a display name, unique against existing ids.
	 *
	 * @param string        $name         Display name.
	 * @param array<string> $existing_ids Already-used ids.
	 *
	 * @return string
	 */
	public static function derive_id( string $name, array $existing_ids ): string {
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'printer';
		}
		$candidate = $base;
		$suffix    = 2;
		while ( \in_array( $candidate, $existing_ids, true ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}
}
