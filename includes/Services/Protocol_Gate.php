<?php
/**
 * WCPOS client protocol gate.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WP_REST_Request;
use WP_REST_Response;

/** Refuses clients whose wire protocol predates the sync surface. */
final class Protocol_Gate {
	public const MIN_PROTOCOL = 2;
	public const SERVER_PROTOCOL = 2;
	public const ERROR_CODE = 'wcpos_update_required';

	/**
	 * Return an update-required response, or null when the request may proceed.
	 *
	 * Only a POS-client request can be told to update: every shipped client
	 * carries the WCPOS marker (`X-WCPOS: 1`, or its `wcpos=1` query twin on
	 * hosts that strip headers), and a request without one is not a client —
	 * "update required" would be the wrong answer for it. Route carve-outs are
	 * the caller's decision (`Route_Classifier::is_protocol_exempt()`).
	 *
	 * @param WP_REST_Request $request Request to inspect.
	 */
	public static function refusal( WP_REST_Request $request ): ?WP_REST_Response {
		if ( ! self::is_marked( $request ) ) {
			return null;
		}

		$signal = Client_Signal::read( $request );
		if ( self::MIN_PROTOCOL <= (int) $signal['protocol'] ) {
			return null;
		}

		$response = new WP_REST_Response(
			array(
				'code'    => self::ERROR_CODE,
				'message' => __( 'This store requires a newer version of WCPOS.', 'woocommerce-pos' ),
				'data'    => array(
					'status'          => 426,
					'min_protocol'    => self::MIN_PROTOCOL,
					'server_protocol' => self::SERVER_PROTOCOL,
					'plugin_version'  => \WCPOS\WooCommercePOS\VERSION,
					'docs'            => 'https://docs.wcpos.com/error-codes/wcpos_update_required',
				),
			),
			426
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Whether the request carries the WCPOS client marker on either channel.
	 *
	 * Read off the request object ONLY, never the ambient globals: the served
	 * request's `?wcpos=1` is already on its query params, and the ambient
	 * query var belongs to that outer request, not to every request dispatched
	 * while serving it (`rest_do_request`, `_embed`, the batch endpoint). A
	 * nested request inherits the ambient marker but not the outer request's
	 * protocol signal, so consulting it would refuse a nested dispatch made on
	 * behalf of a current client — the hazard `Sync\Response_Envelope`
	 * documents for its own marker check. (`wcpos_request( 'header' )` is no
	 * alternative either: it reads the SAPI headers, which a dispatched
	 * request does not populate.)
	 *
	 * Public because the client-signal telemetry must count exactly the
	 * requests the gate can refuse: one marker, one owner.
	 *
	 * @param WP_REST_Request $request Request to inspect.
	 */
	public static function is_marked( WP_REST_Request $request ): bool {
		if ( '1' === trim( (string) $request->get_header( 'X-WCPOS' ) ) ) {
			return true;
		}

		$params = $request->get_query_params();

		return ! empty( $params['wcpos'] );
	}
}
