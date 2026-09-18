<?php
/**
 * Star CloudPRNT provider adapter.
 *
 * @package WCPOS\WooCommercePOS\Services\Providers
 */

namespace WCPOS\WooCommercePOS\Services\Providers;

use WCPOS\WooCommercePOS\Interfaces\Poll_Provider_Adapter_Interface;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Media_Types;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Poll_Request;

/**
 * Star_Cloudprnt_Adapter class.
 */
class Star_Cloudprnt_Adapter implements Poll_Provider_Adapter_Interface {
	/**
	 * Return the StarPRNT content type.
	 *
	 * @return string
	 */
	public function content_type(): string {
		return 'application/vnd.star.starprnt';
	}

	/**
	 * Resolve StarPRNT format data.
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
			'kind'         => 'starprnt',
			'content_type' => $this->content_type(),
		);
	}

	/**
	 * Build a native StarPRNT diagnostic.
	 *
	 * @param string $printer_name Printer display name.
	 *
	 * @return array{content_type:string, payload:string}
	 */
	public function diagnostic( string $printer_name ): array {
		$name  = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $printer_name );
		$date  = gmdate( 'Y-m-d H:i' );
		$bytes = "\x1B\x1D\x29\x55\x02\x00\x30\x01";
		$bytes .= "\x1B\x1D\x29\x55\x02\x00\x40\x00";
		$bytes .= "\x1B\x1D\x61\x01WCPOS\nCloud Print Test\n\x1B\x1D\x61\x00";
		$bytes .= 'Printer: ' . $name . "\nDate: " . $date . "\nIf you can read this, printing works!\n\n\n";
		$bytes .= "\x1B\x64\x03";

		return array(
			'content_type' => $this->content_type(),
			'payload'      => base64_encode( $bytes ),
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
		// The poll body is the printer's half of the conversation. `printingInProgress`
		// says a job is still on the paper, and the spec is explicit that the server
		// must not offer another one until it clears — doing so risks the printer
		// dropping the second job. `clientAction` carries the printer's answers to
		// questions asked in an earlier poll response, which is the only way the
		// protocol exposes what formats the hardware can decode.
		$params = $request['params'];
		$phase = 'POST' === $request['method'] ? 'advertise' : ( 'DELETE' === $request['method'] ? 'result' : 'fetch' );
		$poll = array(
			'phase' => $phase,
			'route' => $request['route'],
			'token' => (int) ( $params['token'] ?? 0 ),
			'type'  => sanitize_text_field( (string) ( $params['type'] ?? '' ) ),
			'code'  => sanitize_text_field( (string) ( $params['code'] ?? '' ) ),
		);
		$poll['needs_capabilities'] = 'advertise' === $phase || ( 'fetch' === $phase && '' === $poll['type'] );
		if ( 'advertise' === $phase ) {
			$body = Cloud_Print_Poll_Request::from_body( $request['body'], $request['json'] );
			$poll['busy'] = $body->printing_in_progress();
			$poll['answers'] = $body->answers();
			$poll['status_code'] = $body->status_code();
		}
		return $poll;
	}

	/**
	 * Build an offer or acknowledgement.
	 *
	 * @param array      $printer  Printer data.
	 * @param array      $poll     Parsed request.
	 * @param array|null $next_job Available job.
	 */
	public function advertise( array $printer, array $poll, ?array $next_job ): array {
		if ( 'not_found' === ( $poll['intent'] ?? '' ) ) {
			return array(
				'status'  => 404,
				'headers' => array(),
				'body'    => array(
					'code'    => 'wcpos_print_job_not_found',
					'message' => __( 'Print job not found.', 'woocommerce-pos' ),
					'data'    => array( 'status' => 404 ),
				),
			);
		}
		$response = array( 'jobReady' => false );
		if ( 'result' === $poll['phase'] ) {
			$response = array( 'ok' => true );
		} elseif ( null !== $next_job ) {
			$media_types = $this->media_types_for_job( $next_job, $printer );
			$response = array(
				'jobReady'   => true,
				'jobToken'   => (string) $next_job['id'],
				'mediaType'  => $media_types[0],
				'mediaTypes' => array_values( $media_types ),
			);
		}
		if ( ! empty( $poll['request_capabilities'] ) ) {
			$response['clientAction'] = array(
				array( 'request' => 'ClientType' ),
				array( 'request' => 'Encodings' ),
			);
		}
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => $response,
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
		// The fetch GET names the printer's chosen media type. A type the server
		// cannot produce is answered with 415 (per the CloudPRNT spec) and the job
		// is left unclaimed. What is servable is deliberately wider than what the
		// poll advertised: the printer naming a type is a stronger signal than our
		// cached capability answer, so a capability update landing between the two
		// requests must not reject a format we had just offered. Firmware that
		// omits the parameter gets our best offer for this printer instead. The
		// logged value is length-capped: printers poll every few seconds, so a
		// wedged loop must not flood the log with unbounded input.
		$servable = ( new Cloud_Print_Media_Types() )->servable_for_job( $job, $printer );
		$requested = $poll['type'];
		$chosen = '' === $requested ? $this->media_types_for_job( $job, $printer )[0] : Cloud_Print_Media_Types::match( $requested, $servable );
		if ( '' === $chosen ) {
			Logger::warning( sprintf( '%s: printer "%s" requested media type "%s" for print job %d, which the server can only serve as %s.', $poll['route'], $printer['id'], substr( $requested, 0, 100 ), (int) $job['id'], implode( ', ', $servable ) ) );
			return array(
				'response' => array(
					'status'  => 415,
					'headers' => array(),
					'body'    => array(
						'code'    => 'wcpos_print_job_incompatible_media_type',
						'message' => __( 'The print job is not available in the requested media type.', 'woocommerce-pos' ),
						'data'    => array( 'status' => 415 ),
					),
				),
			);
		}
		return array( 'media_type' => $chosen );
	}

	/**
	 * Build the print-data response, including empty renders.
	 *
	 * @param array $job    Claimed job.
	 * @param array $render Rendered bytes.
	 * @param array $poll   Parsed request.
	 */
	public function deliver( array $job, array $render, array $poll ): array {
		$chosen = $poll['media_type'];
		return array(
			'status'  => 200,
			'headers' => array_merge( array( 'Content-Type' => $chosen ), self::control_headers( $chosen, $render ) ),
			'body'    => $render['body'],
		);
	}

	/**
	 * Identify the result target.
	 *
	 * @param array $poll Parsed request.
	 */
	public function match_result( array $poll ): array {
		return array( 'job_id' => $poll['token'] );
	}

	/**
	 * Decode the printer result.
	 *
	 * @param array $poll Parsed request.
	 */
	public function interpret_result( array $poll ): array {
		$code = $poll['code'];
		return array(
			'ok'     => '' === $code || '000' === $code || 1 === preg_match( '/^2\d{2,3}(?:\s|$)/', $code ),
			'code'   => $code,
			'detail' => '',
		);
	}

	/**
	 * List the formats this printer can decode.
	 *
	 * @param array $job     Candidate job.
	 * @param array $printer Printer and cached encodings.
	 * @return array Ordered media types.
	 */
	private function media_types_for_job( array $job, array $printer ): array {
		return ( new Cloud_Print_Media_Types() )->for_job( $job, $printer, $printer['encodings'] );
	}

	/**
	 * Peripheral-control headers for a job served in a command-free format.
	 *
	 * `text/plain` and images carry no cut or drawer commands, so CloudPRNT reads
	 * them off the fetch response instead. Command formats express both in-band
	 * and must not also be told to cut, or the receipt cuts twice.
	 *
	 * Both headers are always sent, `none` included. Omitting them leaves the
	 * decision to the printer's own defaults, which cut plain-text jobs — so a
	 * template that deliberately does not cut would cut anyway, and would behave
	 * differently in text than in StarPRNT. Saying `none` out loud keeps the two
	 * formats rendering the same receipt.
	 *
	 * @param string $media_type The media type being served.
	 * @param array  $render     Render result from Print_Job_Service::render_job().
	 *
	 * @return array<string, string>
	 */
	private static function control_headers( string $media_type, array $render ): array {
		if ( ! Cloud_Print_Media_Types::is_header_controlled( $media_type ) ) {
			return array();
		}

		$headers = array(
			'X-Star-Cut'        => null === $render['cut'] ? 'none' : (string) $render['cut'],
			'X-Star-CashDrawer' => null === $render['drawer'] ? 'none' : (string) $render['drawer'],
		);

		// The raster is already two-colour, so the printer's Floyd-Steinberg
		// default would dither an image that has nothing left to dither —
		// softening crisp black-on-white text into stipple.
		if ( Cloud_Print_Media_Types::PNG === Cloud_Print_Media_Types::normalize( $media_type ) ) {
			$headers['X-Star-ImageDitherPattern'] = 'none';
		}

		return $headers;
	}
}
