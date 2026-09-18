<?php
/**
 * Plain-data polling protocol boundary.
 *
 * @package WCPOS\WooCommercePOS\Interfaces
 */

namespace WCPOS\WooCommercePOS\Interfaces;

/**
 * Wire protocols describe requests and responses; the lifecycle owns job state.
 */
interface Poll_Provider_Adapter_Interface extends Provider_Adapter_Interface {
	/**
	 * Parse the request phase and protocol hints.
	 *
	 * @param array $request Params, raw body, JSON, method and route.
	 * @return array Phase (advertise/fetch/result), token and protocol hints.
	 */
	public function parse( array $request ): array;

	/**
	 * Build an offer, acknowledgement or not-found response.
	 *
	 * @param array      $printer  Printer and cached capabilities.
	 * @param array      $poll     Parsed request, capability hint or not-found intent.
	 * @param array|null $next_job Available job, or null for an idle/result response.
	 * @return array{status:int, headers:array, body:string|array}
	 */
	public function advertise( array $printer, array $poll, ?array $next_job ): array;

	/**
	 * Negotiate before claiming the job.
	 *
	 * @param array $printer Printer and cached capabilities.
	 * @param array $poll    Parsed request.
	 * @param array $job     Candidate job, not yet claimed.
	 * @return array Media type, or a response rejecting the request before claiming.
	 */
	public function negotiate( array $printer, array $poll, array $job ): array;

	/**
	 * Build the delivery response, even when rendering failed.
	 *
	 * @param array $job    Claimed job.
	 * @param array $render Rendered bytes (possibly empty) and peripheral controls.
	 * @param array $poll   Parsed request and negotiated media type.
	 * @return array{status:int, headers:array, body:string|array}
	 */
	public function deliver( array $job, array $render, array $poll ): array;

	/**
	 * Identify the result target.
	 *
	 * @param array $poll Parsed result.
	 * @return array{job_id:int|null} Null means active/unconfirmed claim lookup.
	 */
	public function match_result( array $poll ): array;

	/**
	 * Decode the printer result.
	 *
	 * @param array $poll Parsed result.
	 * @return array{ok:bool, code:string, detail:string}
	 */
	public function interpret_result( array $poll ): array;
}
