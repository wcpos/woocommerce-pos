/**
 * Request/response shaping for the Logs screen's paginated fetch.
 *
 * The screen asks for the `_wcpos_envelope=1` body shape
 * (includes/Sync/Response_Envelope.php): the route's own payload under `data`
 * with the pagination totals mirrored under `_wcpos`. Reading
 * `X-WP-TotalPages` off the response headers instead silently collapsed the
 * log to one page behind proxies and WAFs that strip custom headers.
 */
import { addQueryArgs } from '@wordpress/url';

export const LOGS_PER_PAGE = 50;

export interface LogsEnvelope<TBody> {
	data: TBody;
	_wcpos?: { total_pages?: number };
}

/** REST path for one page of the log, with the envelope opt-in and the active filters. */
export function logsRequestPath(page: number, level: string, source: string): string {
	return addQueryArgs('wcpos/v1/logs', {
		wcpos: 1,
		_wcpos_envelope: 1,
		per_page: LOGS_PER_PAGE,
		page,
		...(level === 'all' ? {} : { level }),
		source,
	});
}

function isEnveloped<TBody>(
	response: LogsEnvelope<TBody> | TBody
): response is LogsEnvelope<TBody> {
	return (
		typeof response === 'object' &&
		response !== null &&
		'data' in response &&
		'_wcpos' in response
	);
}

/**
 * Lift the route body out of the envelope and attach the page count the pager needs.
 *
 * A response that arrives un-enveloped (a later `rest_post_dispatch` filter
 * replacing the body, or a future route exemption) is treated as the raw body
 * with a single page, rather than being read as an empty log.
 */
export function unwrapLogsEnvelope<TBody extends object>(
	response: LogsEnvelope<TBody> | TBody
): TBody & { _totalPages: number } {
	if (!isEnveloped(response)) {
		return { ...response, _totalPages: 1 };
	}
	return {
		...response.data,
		_totalPages: response._wcpos?.total_pages ?? 1,
	};
}
