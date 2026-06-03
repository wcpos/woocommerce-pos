<?php
/**
 * Cloud-print provider capabilities value object.
 *
 * Single source of truth for per-provider knowledge: validity, polling,
 * content types, poll endpoints, server diagnostics and thermal wire formats.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Provider class.
 */
class Provider {
	/**
	 * Per-provider capability map.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const CAPABILITIES = array(
		'star-cloudprnt' => array(
			'polling'                    => true,
			'content_type'               => 'application/octet-stream',
			'poll_endpoint'              => 'cloudprnt',
			'supports_server_diagnostic' => true,
			'thermal_wire_format'        => 'escpos',
		),
		'epson-sdp'      => array(
			'polling'                    => true,
			'content_type'               => 'application/xml',
			'poll_endpoint'              => 'epson-sdp',
			'supports_server_diagnostic' => true,
			'thermal_wire_format'        => 'epos-xml',
		),
		'printnode'      => array(
			'polling'                    => false,
			'content_type'               => 'application/pdf',
			'poll_endpoint'              => null,
			'supports_server_diagnostic' => false,
			'thermal_wire_format'        => null,
		),
		'star-online'    => array(
			'polling'                    => false,
			'content_type'               => 'text/vnd.star.markup',
			'poll_endpoint'              => null,
			'supports_server_diagnostic' => false,
			'thermal_wire_format'        => 'star-markup',
		),
	);

	/**
	 * List of valid provider keys.
	 *
	 * @return array<int, string>
	 */
	public static function valid(): array {
		return array_keys( self::CAPABILITIES );
	}

	/**
	 * Whether the provider polls the server for jobs.
	 *
	 * @param string $provider Provider key.
	 *
	 * @return bool
	 */
	public static function is_polling( string $provider ): bool {
		return (bool) ( self::CAPABILITIES[ $provider ]['polling'] ?? false );
	}

	/**
	 * Whether the provider needs an out-of-band submit (we push jobs to it),
	 * as opposed to a polling provider that fetches jobs itself.
	 *
	 * @param string $provider Provider key.
	 *
	 * @return bool
	 */
	public static function requires_submit( string $provider ): bool {
		return \in_array( $provider, self::valid(), true ) && ! self::is_polling( $provider );
	}

	/**
	 * HTTP content type for the provider's job payloads.
	 *
	 * @param string $provider Provider key.
	 *
	 * @return string
	 */
	public static function content_type( string $provider ): string {
		return (string) ( self::CAPABILITIES[ $provider ]['content_type'] ?? 'application/octet-stream' );
	}

	/**
	 * REST poll-endpoint slug for the provider.
	 *
	 * @param string $provider Provider key.
	 *
	 * @return string|null
	 */
	public static function poll_endpoint( string $provider ): ?string {
		return self::CAPABILITIES[ $provider ]['poll_endpoint'] ?? null;
	}

	/**
	 * Whether the provider supports a server-built diagnostic payload.
	 *
	 * @param string $provider Provider key.
	 *
	 * @return bool
	 */
	public static function supports_server_diagnostic( string $provider ): bool {
		return (bool) ( self::CAPABILITIES[ $provider ]['supports_server_diagnostic'] ?? false );
	}

	/**
	 * Thermal wire format for the given provider/engine pair.
	 *
	 * Only the 'thermal' engine on a direct printer yields a wire format;
	 * any other engine, or an unknown provider, returns null.
	 *
	 * @param string $provider Provider key.
	 * @param string $engine   Render engine (e.g. 'thermal', 'logicless').
	 *
	 * @return string|null
	 */
	public static function wire_format( string $provider, string $engine ): ?string {
		if ( 'thermal' !== $engine ) {
			return null;
		}

		return self::CAPABILITIES[ $provider ]['thermal_wire_format'] ?? null;
	}
}
