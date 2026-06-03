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

	const RUNTIME_OPTION = 'woocommerce_pos_cloud_print_runtime';
	const SEEN_TTL       = 150; // Seconds; connected if seen within this window.
	const PN_STATUS_TTL  = 60;  // Seconds; PrintNode live-status cache window.

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

	/**
	 * Record that a printer polled just now.
	 *
	 * @param string $printer_id Printer id.
	 */
	public function record_seen( string $printer_id ): void {
		$runtime                = get_option( self::RUNTIME_OPTION, array() );
		$runtime                = \is_array( $runtime ) ? $runtime : array();
		$runtime[ $printer_id ] = time();
		update_option( self::RUNTIME_OPTION, $runtime, false ); // Autoload no.
	}

	/**
	 * Get a printer's last-seen unix timestamp (0 if never).
	 *
	 * @param string $printer_id Printer id.
	 *
	 * @return int
	 */
	public function get_seen( string $printer_id ): int {
		$runtime = get_option( self::RUNTIME_OPTION, array() );

		return ( \is_array( $runtime ) && isset( $runtime[ $printer_id ] ) ) ? (int) $runtime[ $printer_id ] : 0;
	}

	/**
	 * Drop runtime last-seen entries for printer ids that no longer exist.
	 *
	 * Prevents the runtime option from growing unbounded as printers are
	 * removed, and stops a recreated id (slug reuse) from inheriting a deleted
	 * printer's stale status.
	 *
	 * @param array<string> $keep_ids Printer ids to retain.
	 */
	public function prune_seen( array $keep_ids ): void {
		$runtime = get_option( self::RUNTIME_OPTION, array() );
		if ( ! \is_array( $runtime ) ) {
			return;
		}
		$pruned = array_intersect_key( $runtime, array_flip( $keep_ids ) );
		if ( $pruned !== $runtime ) {
			update_option( self::RUNTIME_OPTION, $pruned, false );
		}
	}

	/**
	 * Connection status for a printer.
	 *
	 * For PrintNode printers this returns PrintNode's live vocabulary
	 * ('online'|'offline'|'unknown'), cached briefly. For polling printers
	 * (Star/Epson) it returns 'waiting' (never polled), 'connected' (polled
	 * within SEEN_TTL), or 'offline' (polled, but stale).
	 *
	 * @param string $printer_id Printer id.
	 *
	 * @return string
	 */
	public function status_for( string $printer_id ): string {
		$printer = $this->get_printer( $printer_id );
		if ( null !== $printer && 'printnode' === ( $printer['provider'] ?? '' ) ) {
			return $this->printnode_status( $printer );
		}

		if ( null !== $printer && 'star-online' === ( $printer['provider'] ?? '' ) ) {
			return $this->star_online_status( $printer );
		}

		$seen = $this->get_seen( $printer_id );
		if ( 0 === $seen ) {
			return 'waiting';
		}

		return ( time() - $seen ) <= self::SEEN_TTL ? 'connected' : 'offline';
	}

	/**
	 * Resolve a PrintNode printer's live status, cached for PN_STATUS_TTL seconds.
	 *
	 * All outcomes are cached, including 'unknown'/'offline', to avoid hammering
	 * the PrintNode API on every settings read.
	 *
	 * @param array $printer Registered PrintNode printer.
	 *
	 * @return string 'online', 'offline', or 'unknown'.
	 */
	private function printnode_status( array $printer ): string {
		$key    = 'wcpos_cloud_print_pn_status_' . md5( (string) $printer['id'] );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$api_key       = (string) ( $printer['printnode_api_key'] ?? '' );
		$pn_printer_id = (int) ( $printer['printnode_printer_id'] ?? 0 );
		if ( '' === $api_key || 0 === $pn_printer_id ) {
			$status = 'unknown';
		} else {
			$status = ( new PrintNode_Client( $api_key ) )->printer_state( $pn_printer_id );
		}

		set_transient( $key, $status, self::PN_STATUS_TTL );

		return $status;
	}
	/**
	 * Resolve a Star Online device's live status, cached for PN_STATUS_TTL seconds.
	 *
	 * @param array $printer Registered star-online printer.
	 *
	 * @return string 'online', 'offline', or 'unknown'.
	 */
	private function star_online_status( array $printer ): string {
		$key    = 'wcpos_cloud_print_star_status_' . md5( (string) $printer['id'] );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$api_key   = (string) ( $printer['star_api_key'] ?? '' );
		$url       = (string) ( $printer['star_cloudprnt_url'] ?? '' );
		$device_id = (string) ( $printer['star_device_id'] ?? '' );
		$api_base  = Star_Online_Client::api_base_from_cloudprnt_url( $url );
		$group     = Star_Online_Client::group_from_cloudprnt_url( $url );

		if ( '' === $api_key || null === $api_base || '' === $group || '' === $device_id ) {
			$status = 'unknown';
		} else {
			$status = ( new Star_Online_Client( $api_base, $api_key ) )->device_state( $group, $device_id );
		}

		set_transient( $key, $status, self::PN_STATUS_TTL );

		return $status;
	}
}
