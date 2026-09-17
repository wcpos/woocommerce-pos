<?php
/**
 * Epson Server Direct Print provider adapter.
 *
 * @package WCPOS\WooCommercePOS\Services\Providers
 */

namespace WCPOS\WooCommercePOS\Services\Providers;

use WCPOS\WooCommercePOS\Interfaces\Poll_Provider_Adapter_Interface;

/**
 * Epson_Sdp_Adapter class.
 */
class Epson_Sdp_Adapter implements Poll_Provider_Adapter_Interface {
	/**
	 * Milliseconds an Epson Server Direct Print printer waits for the local
	 * print device to become printable before it gives up and reports
	 * EX_TIMEOUT.
	 *
	 * Epson allows 5000–300000. The previous 10000 (near the floor) was
	 * unforgiving for printers that briefly go not-ready — a paper change, a
	 * sleep/wake, or a momentary network blip — declaring the job failed
	 * before the printer could recover. 60000 gives those transient states
	 * room to clear without letting a genuinely offline printer hang for long.
	 */
	const EPSON_SDP_PRINT_TIMEOUT_MS = 60000;

	/**
	 * Return the ePOS-Print XML content type.
	 *
	 * @return string
	 */
	public function content_type(): string {
		return 'application/xml';
	}

	/**
	 * Resolve ePOS-Print XML format data.
	 *
	 * @param array $printer  Printer configuration.
	 * @param array $template Template configuration.
	 *
	 * @return array{kind:string, content_type:string}
	 */
	public function format( array $printer, array $template ): array {
		if ( 'thermal' !== (string) ( $template['engine'] ?? '' ) ) {
			return array(
				'kind'         => '',
				'content_type' => '',
			);
		}

		return array(
			'kind'         => 'epos-xml',
			'content_type' => $this->content_type(),
		);
	}

	/**
	 * Build an ePOS-Print XML diagnostic.
	 *
	 * @param string $printer_name Printer display name.
	 *
	 * @return array{content_type:string, payload:string}
	 */
	public function diagnostic( string $printer_name ): array {
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $printer_name );
		$text = "WCPOS - Cloud Print Test\nPrinter: " . $name . "\n";
		$text .= 'Date: ' . gmdate( 'Y-m-d H:i' ) . "\nIf you can read this, printing works!\n";
		// Three blank lines before the cut, the same bottom margin the receipt
		// templates use, so the last line is not flush against the blade.
		$xml = '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">'
			. '<text>' . esc_html( $text ) . '</text><feed line="3"/><cut type="feed"/></epos-print>';

		return array(
			'content_type' => $this->content_type(),
			'payload'      => base64_encode( $xml ),
		);
	}

	/**
	 * Resolve polling status.
	 *
	 * @param array $printer Printer configuration.
	 * @param array $context Runtime status context.
	 *
	 * @return string
	 */
	public function status( array $printer, array $context ): string {
		$relay = $context['relay_status'] ?? null;
		if ( \is_array( $relay ) && 'blocked' === ( $relay['origin_status'] ?? '' ) ) {
			return 'blocked';
		}
		if ( \is_array( $relay ) && null !== ( $relay['last_seen_seconds_ago'] ?? null ) && (int) $relay['last_seen_seconds_ago'] <= (int) $context['seen_ttl'] ) {
			return 'connected';
		}

		$seen = (int) ( $context['seen'] ?? 0 );
		if ( 0 === $seen ) {
			return 'waiting';
		}

		return ( (int) $context['now'] - $seen ) <= (int) $context['seen_ttl'] ? 'connected' : 'offline';
	}

	/**
	 * Parse the vendor request.
	 *
	 * @param array $request Request data.
	 */
	public function parse( array $request ): array {
		$params = $request['params'];
		// Server Direct Print multiplexes three different request types onto the
		// one configured URL, as URL-encoded form data distinguished by
		// ConnectionType (User's Manual Rev.K, ch.3 and the Test_print.php
		// reference implementation):
		//
		// GetRequest  — poll for a job
		// SetResponse — printing result; the XML rides in the ResponseFile field
		// SetStatus   — status notification; the XML rides in the Status field
		//
		// Answering a status notification with print data hands the job to a
		// request that discards it, so the printer never prints and the job stays
		// claimed. Dispatching on ConnectionType is what keeps the job on the
		// GetRequest that is actually asking for one.
		$connection_type = (string) ( $params['ConnectionType'] ?? '' );
		$result_xml = (string) ( $params['ResponseFile'] ?? '' );
		if ( '' === $result_xml && false !== strpos( $request['body'], '<response' ) ) {
			$result_xml = $request['body'];
		}
		// Idle PrintResponseInfo and SetStatus posts must never consume a job.
		$phase = false !== strpos( $result_xml, '<response' ) ? 'result' : ( 'SetStatus' === $connection_type || 'SetResponse' === $connection_type || '' !== $result_xml ? 'advertise' : 'fetch' );
		return array(
			'phase'      => $phase,
			'route'      => $request['route'],
			'result_xml' => $result_xml,
			'busy'       => 'advertise' === $phase,
		);
	}

	/**
	 * Build an offer or acknowledgement.
	 *
	 * @param array      $printer  Printer data.
	 * @param array      $poll     Parsed request.
	 * @param array|null $next_job Available job.
	 */
	public function advertise( array $printer, array $poll, ?array $next_job ): array {
		return array(
			'status'  => 200,
			'headers' => array( 'Content-Type' => 'text/xml; charset=utf-8' ),
			'body'    => '<response success="true" code="" status=""/>',
		);
	}

	/**
	 * Choose a format before claiming.
	 *
	 * @param array $printer Printer data.
	 * @param array $poll    Parsed request.
	 * @param array $job     Candidate job.
	 */
	public function negotiate( array $printer, array $poll, array $job ): array {
		// SDP uses the provider default render, not an HTTP Accept media type.
		return array( 'media_type' => '' );
	}

	/**
	 * Build the print-data response, including empty renders.
	 *
	 * @param array $job    Claimed job.
	 * @param array $render Rendered bytes.
	 * @param array $poll   Parsed request.
	 */
	public function deliver( array $job, array $render, array $poll ): array {
		if ( '' === $render['body'] ) {
			return $this->advertise( array(), $poll, null );
		}
		// SDP 1.00 is supported by every printer family; this is not a SOAP envelope.
		$envelope  = '<?xml version="1.0" encoding="utf-8"?>';
		$envelope .= '<PrintRequestInfo Version="1.00"><ePOSPrint>';
		$envelope .= '<Parameter><devid>local_printer</devid><timeout>' . self::EPSON_SDP_PRINT_TIMEOUT_MS . '</timeout></Parameter>';
		$envelope .= '<PrintData>' . $render['body'] . '</PrintData>';
		$envelope .= '</ePOSPrint></PrintRequestInfo>';
		return array(
			'status'  => 200,
			'headers' => array( 'Content-Type' => 'text/xml; charset=utf-8' ),
			'body'    => $envelope,
		);
	}

	/**
	 * Identify the result target.
	 *
	 * @param array $poll Parsed request.
	 */
	public function match_result( array $poll ): array {
		// SDP 1.00 carries no job token: match the active or latest unconfirmed claim.
		return array( 'job_id' => null );
	}

	/**
	 * Decode the printer result.
	 *
	 * @param array $poll Parsed request.
	 */
	public function interpret_result( array $poll ): array {
		$result_xml = $poll['result_xml'];
		$code = 'unknown';
		if ( 1 === preg_match( '/\bcode="([^"]*)"/', $result_xml, $matches ) ) {
			$code = sanitize_text_field( $matches[1] );
		}
		$status = null;
		if ( 1 === preg_match( '/\bstatus="(\d+)"/', $result_xml, $matches ) ) {
			// Do not misdecode unsigned bit 31 on a 32-bit PHP build.
			$status = (float) $matches[1] <= PHP_INT_MAX ? (int) $matches[1] : null;
		}
		$flags = null === $status ? '' : implode( ', ', self::describe_epson_status( $status ) );
		$detail = null === $status ? '' : sprintf( ' (0x%08X%s)', $status, '' === $flags ? '' : ': ' . $flags );
		return array(
			'ok'     => false !== strpos( $result_xml, 'success="true"' ),
			'code'   => $code,
			'detail' => $detail,
		);
	}

	/**
	 * Decode an Epson ePOS-Print response status bitmask.
	 *
	 * @param int $status Decimal ASB status bitmask.
	 *
	 * @return array<int, string>
	 */
	private static function describe_epson_status( int $status ): array {
		// Epson ePOS-Print XML User's Manual, response `status` table (ASB bits).
		// Fault bits only: informational ones (print complete, drawer pin, feed
		// button, panel switch, buzzer) say nothing about why a print failed.
		$labels = array(
			0x00000001 => __( 'no response from printer', 'woocommerce-pos' ),
			0x00000008 => __( 'offline', 'woocommerce-pos' ),
			0x00000020 => __( 'cover open', 'woocommerce-pos' ),
			0x00000100 => __( 'waiting for online recovery', 'woocommerce-pos' ),
			0x00000400 => __( 'mechanical error', 'woocommerce-pos' ),
			0x00000800 => __( 'autocutter error', 'woocommerce-pos' ),
			0x00002000 => __( 'unrecoverable error', 'woocommerce-pos' ),
			0x00004000 => __( 'auto-recoverable error', 'woocommerce-pos' ),
			0x00020000 => __( 'paper near end', 'woocommerce-pos' ),
			0x00080000 => __( 'paper end', 'woocommerce-pos' ),
			0x80000000 => __( 'spooler stopped', 'woocommerce-pos' ),
		);

		$descriptions = array();
		foreach ( $labels as $bit => $label ) {
			if ( 0 !== ( $status & $bit ) ) {
				$descriptions[] = $label;
			}
		}

		return $descriptions;
	}
}
