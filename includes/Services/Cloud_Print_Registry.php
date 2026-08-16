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
	 * All registered cloud printers.
	 *
	 * @return array<int, array>
	 */
	public function get_printers(): array {
		$settings = get_option( self::OPTION, array() );

		return isset( $settings['printers'] ) && \is_array( $settings['printers'] ) ? $settings['printers'] : array();
	}

	/**
	 * Get a registered cloud printer by id.
	 *
	 * @param string $printer_id Printer id.
	 *
	 * @return array|null
	 */
	public function get_printer( string $printer_id ): ?array {
		foreach ( $this->get_printers() as $printer ) {
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
		if ( null !== $printer ) {
			$provider = Provider::normalize( \is_string( $printer['provider'] ?? null ) ? $printer['provider'] : null );
			$adapter  = Provider::adapter( $provider );
			if ( null !== $adapter ) {
				return $adapter->status(
					$printer,
					array(
						'now'          => time(),
						'seen'         => $this->get_seen( $printer_id ),
						'seen_ttl'     => self::SEEN_TTL,
						'cache_ttl'    => self::PN_STATUS_TTL,
						'relay_status' => Provider::is_polling( $provider ) ? Cloud_Print_Relay_Service::status( $printer_id ) : null,
					)
				);
			}
		}

		/*
		 * Shared fallthrough, kept from the pre-adapter implementation: a
		 * printer_id with a recorded poll but no registry row — or a row whose
		 * provider is unrecognised — is still reported from its last-seen
		 * timestamp, not as 'waiting'. Returning early here instead reported a
		 * recently-polled printer as never-seen.
		 * `test_status_connected_when_recently_seen` pins this.
		 */
		$seen = $this->get_seen( $printer_id );
		if ( 0 === $seen ) {
			return 'waiting';
		}

		return ( time() - $seen ) <= self::SEEN_TTL ? 'connected' : 'offline';
	}

	/**
	 * The relay's block signal for a printer, when it reports one.
	 *
	 * Delegates to the relay service's transient-cached status, so this is
	 * safe to call in any order relative to status_for().
	 *
	 * @param string $printer_id Printer ID.
	 *
	 * @return string|null
	 */
	public function status_detail_for( string $printer_id ): ?string {
		return Cloud_Print_Relay_Service::status_detail( $printer_id );
	}
}
