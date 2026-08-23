<?php
/**
 * Star CloudPRNT media-type negotiation.
 *
 * CloudPRNT negotiates the wire format: the poll response offers an ordered list
 * of media types the server can produce, the printer picks the one it prefers
 * from the subset it can decode, and names that pick in the job fetch's `?type`.
 * Offering a single format is the degenerate case — if the printer cannot decode
 * it there is no GET at all, just a `510 Incompatible Media Type` confirmation.
 *
 * Two things bound the offer:
 *
 * - What the server can render for this job. A template-backed job can be
 *   emitted in any format the thermal pipeline supports; a job whose bytes were
 *   uploaded by the POS app can only ever be served back as what was uploaded.
 * - What the printer told us it decodes, from its cached `Encodings` answer. A
 *   Line Mode-only model (TSP650II/TSP700II/TSP800II) does not list StarPRNT,
 *   and offering it a StarPRNT-only list guarantees the 510.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Cloud_Print_Media_Types class.
 */
class Cloud_Print_Media_Types {
	/**
	 * Native StarPRNT command data.
	 */
	const STARPRNT = 'application/vnd.star.starprnt';

	/**
	 * Plain text — decodable by every Star CloudPRNT model.
	 */
	const TEXT = 'text/plain';

	/**
	 * Fallback for a stored payload with no recorded content type.
	 */
	const OCTET_STREAM = 'application/octet-stream';

	/**
	 * Formats the server can render a Star template job into, best first.
	 *
	 * StarPRNT leads because it is native: crisp printer fonts, in-band cut and
	 * drawer, and no raster weight. `text/plain` follows as the universal floor.
	 *
	 * @var array<int, string>
	 */
	private const STAR_RENDERABLE = array( self::STARPRNT, self::TEXT );

	/**
	 * Media types whose peripherals are requested with response headers.
	 *
	 * Command formats carry cut and drawer in the byte stream. Command-free
	 * formats cannot, so CloudPRNT moves them to `X-Star-Cut` and
	 * `X-Star-CashDrawer` on the job fetch response.
	 *
	 * @var array<int, string>
	 */
	private const HEADER_CONTROLLED = array( self::TEXT );

	/**
	 * Every media type the server can produce for a job, best first.
	 *
	 * This is the set the fetch validates against, deliberately unfiltered by
	 * the printer's cached capabilities. The capability filter shapes what we
	 * *advertise*; once the printer has named a type, it has told us more
	 * directly than any cache can that it wants those bytes. Re-applying the
	 * filter at fetch time would let a capability answer that arrived between
	 * the poll and the fetch reject a type we had just offered — a 415 for a
	 * format both ends can handle, leaving the receipt unprinted.
	 *
	 * @param array      $job     Job array from Print_Job_Service::get().
	 * @param array|null $printer Printer row, or null when unregistered.
	 *
	 * @return array<int, string> Always at least one entry.
	 */
	public function servable_for_job( array $job, ?array $printer ): array {
		if ( ! $this->is_renderable( $job, $printer ) ) {
			// Uploaded bytes: the only thing we have is what is in the store.
			return array(
				'' !== (string) ( $job['content_type'] ?? '' )
					? (string) $job['content_type']
					: self::OCTET_STREAM,
			);
		}

		return self::STAR_RENDERABLE;
	}

	/**
	 * The media types to advertise for a job, in the order we prefer them.
	 *
	 * @param array      $job       Job array from Print_Job_Service::get().
	 * @param array|null $printer   Printer row, or null when unregistered.
	 * @param array      $encodings The printer's cached `Encodings` answer.
	 *
	 * @return array<int, string> Always at least one entry.
	 */
	public function for_job( array $job, ?array $printer, array $encodings = array() ): array {
		return $this->prefer_decodable( $this->servable_for_job( $job, $printer ), $encodings );
	}

	/**
	 * The thermal wire format that produces a media type.
	 *
	 * @param string $media_type The negotiated media type.
	 *
	 * @return string The wire format, or '' when the type is not server-rendered.
	 */
	public static function wire_format( string $media_type ): string {
		switch ( self::normalize( $media_type ) ) {
			case self::STARPRNT:
				return 'starprnt';
			case self::TEXT:
				return 'text';
			default:
				return '';
		}
	}

	/**
	 * Whether cut and drawer for this media type travel as response headers.
	 *
	 * @param string $media_type The negotiated media type.
	 *
	 * @return bool
	 */
	public static function is_header_controlled( string $media_type ): bool {
		return \in_array( self::normalize( $media_type ), self::HEADER_CONTROLLED, true );
	}

	/**
	 * Find the offered media type a printer's requested type refers to.
	 *
	 * RFC 2045 makes media types case-insensitive and allows parameters, so
	 * `Text/Plain; charset=utf-8` and `text/plain` are the same offer.
	 *
	 * @param string             $requested The `?type` the printer asked for.
	 * @param array<int, string> $offered   The media types the poll offered.
	 *
	 * @return string The matching offered type, or '' when none matches.
	 */
	public static function match( string $requested, array $offered ): string {
		$needle = self::normalize( $requested );
		if ( '' === $needle ) {
			return '';
		}

		foreach ( $offered as $candidate ) {
			if ( self::normalize( $candidate ) === $needle ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Reduce a media type to its lower-cased `type/subtype`.
	 *
	 * @param string $media_type The raw media type.
	 *
	 * @return string
	 */
	public static function normalize( string $media_type ): string {
		$base = strtok( $media_type, ';' );

		return strtolower( trim( false === $base ? '' : $base ) );
	}

	/**
	 * Whether the server can re-render this job into an arbitrary format.
	 *
	 * A template-backed job for a provider with a thermal wire format is
	 * rendered at fetch time and can therefore be produced in any format the
	 * pipeline supports. Everything else — an uploaded payload, a template the
	 * provider cannot render — is served as stored.
	 *
	 * @param array      $job     Job array.
	 * @param array|null $printer Printer row.
	 *
	 * @return bool
	 */
	private function is_renderable( array $job, ?array $printer ): bool {
		if ( empty( $job['order_id'] ) || empty( $job['template_id'] ) || ! empty( $job['pn_kind'] ) ) {
			return false;
		}

		$provider = Provider::normalize( \is_string( $printer['provider'] ?? null ) ? $printer['provider'] : null );
		if ( 'star-cloudprnt' !== $provider ) {
			return false;
		}

		$template = Print_Job_Service::load_template( (string) $job['template_id'] );

		return null !== $template && null !== Provider::wire_format( $provider, (string) ( $template['engine'] ?? '' ) );
	}

	/**
	 * Order an offer by what the printer says it can decode.
	 *
	 * When the printer has answered `Encodings`, formats it did not list are
	 * dropped — offering them can only produce a 510. When it has not answered,
	 * or lists none of ours, the full list goes out: a printer that rejects
	 * everything is no worse off than one that was offered nothing, and the
	 * common case of firmware that ignores `clientAction` still prints.
	 *
	 * @param array<int, string> $renderable Formats the server can produce.
	 * @param array<int, string> $encodings  The printer's cached `Encodings`.
	 *
	 * @return array<int, string>
	 */
	private function prefer_decodable( array $renderable, array $encodings ): array {
		if ( array() === $encodings ) {
			return $renderable;
		}

		$decodable = array();
		foreach ( $renderable as $type ) {
			if ( '' !== self::match( $type, $encodings ) ) {
				$decodable[] = $type;
			}
		}

		return array() === $decodable ? $renderable : $decodable;
	}
}
