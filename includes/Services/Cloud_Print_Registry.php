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
	 * Per-printer CloudPRNT client capabilities, keyed by printer id.
	 *
	 * Separate from RUNTIME_OPTION, whose values are bare last-seen timestamps.
	 */
	const CAPABILITIES_OPTION = 'woocommerce_pos_cloud_print_capabilities';

	/**
	 * Seconds before a printer's capability answers are asked for again.
	 *
	 * Star's own WooCommerce plugin re-asks every 120 seconds; matching it keeps
	 * a swapped-out printer on a reused id from serving formats the new hardware
	 * cannot decode for longer than a couple of minutes.
	 */
	const CAPABILITIES_TTL = 120;

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
	 * A printer's cached CloudPRNT client capabilities.
	 *
	 * Answers are returned however stale they are: a printer's decodable format
	 * list does not change while it sits on a shelf, and serving the last known
	 * answer beats falling back to "offer everything" on every cold cache. The
	 * TTL governs how often we ask again, not how long an answer is believed.
	 *
	 * @param string $printer_id Printer id.
	 *
	 * @return array{client_type:string, client_version:string, encodings:array<int, string>, status_code:string, updated:int, asked:int}
	 */
	public function get_capabilities( string $printer_id ): array {
		$all    = get_option( self::CAPABILITIES_OPTION, array() );
		$stored = ( \is_array( $all ) && isset( $all[ $printer_id ] ) && \is_array( $all[ $printer_id ] ) )
			? $all[ $printer_id ]
			: array();

		return array(
			'client_type'    => (string) ( $stored['client_type'] ?? '' ),
			'client_version' => (string) ( $stored['client_version'] ?? '' ),
			'encodings'      => \is_array( $stored['encodings'] ?? null ) ? array_values( array_map( 'strval', $stored['encodings'] ) ) : array(),
			'status_code'    => (string) ( $stored['status_code'] ?? '' ),
			'updated'        => (int) ( $stored['updated'] ?? 0 ),
			'asked'          => (int) ( $stored['asked'] ?? 0 ),
		);
	}

	/**
	 * Store the answers a printer gave to our `clientAction` questions.
	 *
	 * Only the fields the printer actually answered are overwritten — a poll
	 * that carries `ClientType` but not `Encodings` must not wipe an encodings
	 * list we already know.
	 *
	 * @param string                $printer_id  Printer id.
	 * @param array<string, string> $answers     Answers keyed by request name.
	 * @param string                $status_code The printer's reported status code.
	 *
	 * @return bool Whether anything was stored.
	 */
	public function record_capabilities( string $printer_id, array $answers, string $status_code = '' ): bool {
		$record  = $this->get_capabilities( $printer_id );
		$changed = false;

		foreach ( $answers as $name => $value ) {
			switch ( $name ) {
				case 'ClientType':
					$record['client_type'] = $value;
					$changed               = true;
					break;
				case 'ClientVersion':
					$record['client_version'] = $value;
					$changed                  = true;
					break;
				case 'Encodings':
					$record['encodings'] = self::parse_encodings( $value );
					$changed             = true;
					break;
			}
		}

		if ( '' !== $status_code && $status_code !== $record['status_code'] ) {
			$record['status_code'] = $status_code;
			$changed               = true;
		}

		if ( ! $changed ) {
			return false;
		}

		$record['updated'] = time();
		$this->write_capabilities( $printer_id, $record );

		return true;
	}

	/**
	 * Whether the printer should be asked for its capabilities on this poll.
	 *
	 * @param string $printer_id Printer id.
	 *
	 * @return bool
	 */
	public function should_request_capabilities( string $printer_id ): bool {
		$record = $this->get_capabilities( $printer_id );

		return ( time() - $record['asked'] ) >= self::CAPABILITIES_TTL;
	}

	/**
	 * Record that this poll response carried capability questions.
	 *
	 * Written even when the printer never answers, so firmware that ignores
	 * `clientAction` is asked once per TTL rather than on every poll.
	 *
	 * @param string $printer_id Printer id.
	 */
	public function record_capability_request( string $printer_id ): void {
		$record          = $this->get_capabilities( $printer_id );
		$record['asked'] = time();
		$this->write_capabilities( $printer_id, $record );
	}

	/**
	 * Drop cached capabilities for printer ids that no longer exist.
	 *
	 * @param array<string> $keep_ids Printer ids to retain.
	 */
	public function prune_capabilities( array $keep_ids ): void {
		$all = get_option( self::CAPABILITIES_OPTION, array() );
		if ( ! \is_array( $all ) ) {
			return;
		}
		$pruned = array_intersect_key( $all, array_flip( $keep_ids ) );
		if ( $pruned !== $all ) {
			update_option( self::CAPABILITIES_OPTION, $pruned, false );
		}
	}

	/**
	 * Persist one printer's capability record.
	 *
	 * @param string $printer_id Printer id.
	 * @param array  $record     The record to store.
	 */
	private function write_capabilities( string $printer_id, array $record ): void {
		$all                = get_option( self::CAPABILITIES_OPTION, array() );
		$all                = \is_array( $all ) ? $all : array();
		$all[ $printer_id ] = $record;
		update_option( self::CAPABILITIES_OPTION, $all, false ); // Autoload no.
	}

	/**
	 * Read a printer's `Encodings` answer into a list of media types.
	 *
	 * The answer is a delimited string of the media types the client can decode
	 * (Star's own plugin substring-matches it). Splitting on both `,` and `;`
	 * and then keeping only `type/subtype` tokens drops MIME parameters such as
	 * `charset=utf-8` without needing to know which delimiter the firmware used.
	 *
	 * @param string $value The raw `Encodings` answer.
	 *
	 * @return array<int, string> Lower-cased media types, in the printer's order.
	 */
	private static function parse_encodings( string $value ): array {
		$tokens = preg_split( '/[,;\r\n\t ]+/', strtolower( $value ) );
		if ( false === $tokens ) {
			return array();
		}

		$types = array();
		foreach ( $tokens as $token ) {
			$token = trim( $token );
			if ( 1 === preg_match( '#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $token ) ) {
				$types[] = $token;
			}
		}

		return array_values( array_unique( $types ) );
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
