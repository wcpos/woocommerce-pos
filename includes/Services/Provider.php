<?php
/**
 * Cloud-print provider capabilities value object.
 *
 * Single source of truth for per-provider knowledge: validity, the default for
 * rows without a provider, polling, content types, poll endpoints, server
 * diagnostics, thermal wire formats and renderable template engines.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Provider class.
 */
class Provider {
	/**
	 * Provider assumed for printer rows that predate the provider field.
	 *
	 * Star CloudPRNT was the only provider before the field existed, so a row
	 * without one is a Star CloudPRNT printer.
	 */
	public const DEFAULT_PROVIDER = 'star-cloudprnt';

	/**
	 * Per-provider capability map.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const CAPABILITIES = array(
		// StarPRNT-native printers (the whole TSP100 line) cannot decode
		// ESC/POS; Star's docs advise against octet-stream for command data,
		// so jobs are emitted as native StarPRNT under the vnd.star type.
		'star-cloudprnt' => array(
			'polling'                    => true,
			'content_type'               => 'application/vnd.star.starprnt',
			'poll_endpoint'              => 'cloudprnt',
			'supports_server_diagnostic' => true,
			'thermal_wire_format'        => 'starprnt',
			'template_engines'           => 'thermal',
		),
		'epson-sdp'      => array(
			'polling'                    => true,
			'content_type'               => 'application/xml',
			'poll_endpoint'              => 'epson-sdp',
			'supports_server_diagnostic' => true,
			'thermal_wire_format'        => 'epos-xml',
			'template_engines'           => 'thermal',
		),
		'printnode'      => array(
			'polling'                    => false,
			'content_type'               => 'application/pdf',
			'poll_endpoint'              => null,
			'supports_server_diagnostic' => false,
			'thermal_wire_format'        => null,
			'template_engines'           => 'all',
		),
		'star-online'    => array(
			'polling'                    => false,
			'content_type'               => 'text/vnd.star.markup',
			'poll_endpoint'              => null,
			'supports_server_diagnostic' => false,
			'thermal_wire_format'        => 'star-markup',
			'template_engines'           => 'thermal',
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
	 * Resolve a stored printer row's provider to a known provider key.
	 *
	 * Callers read `$printer['provider']` from an option that predates the
	 * field, so the value can be missing, empty, or (for hand-edited options)
	 * a key this build does not know. All three resolve to the default rather
	 * than to a silent no-provider state.
	 *
	 * @param string|null $provider Raw provider value from a printer row.
	 *
	 * @return string A key from self::valid().
	 */
	public static function normalize( ?string $provider ): string {
		return \in_array( $provider, self::valid(), true ) ? (string) $provider : self::DEFAULT_PROVIDER;
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

	/**
	 * Receipt-template engines the provider can render for automatic jobs.
	 *
	 * 'all' means every active template; 'thermal' means thermal templates
	 * only. Unknown providers are treated as thermal-only, the conservative
	 * answer for a printer we cannot render a PDF for.
	 *
	 * @param string $provider Provider key.
	 *
	 * @return string 'all' or 'thermal'.
	 */
	public static function template_engines( string $provider ): string {
		return (string) ( self::CAPABILITIES[ $provider ]['template_engines'] ?? 'thermal' );
	}

	/**
	 * Per-provider facts the settings UI needs, keyed by provider.
	 *
	 * Projected into the cloud-print settings response so the admin app can
	 * read provider capabilities from the server instead of re-declaring them
	 * in its own table.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function client_capabilities(): array {
		$capabilities = array();
		foreach ( self::valid() as $provider ) {
			$capabilities[ $provider ] = array(
				'polling'          => self::is_polling( $provider ),
				'poll_endpoint'    => self::poll_endpoint( $provider ),
				'template_engines' => self::template_engines( $provider ),
			);
		}

		return $capabilities;
	}
}
