/**
 * Request/response shaping for the Logs screen's paginated fetch.
 *
 * The screen asks for the `_wcpos_envelope=1` body shape
 * (includes/Sync/Response_Envelope.php): the route's own payload under `data`
 * with the pagination totals mirrored under `_wcpos`. Reading
 * `X-WP-TotalPages` off the response headers instead silently collapsed the
 * log to one page behind proxies and WAFs that strip custom headers.
 */

export const LOGS_PER_PAGE = 50;

export interface LogsEnvelope<TBody> {
	data: TBody;
	_wcpos?: { total_pages?: number };
}

/** REST path for one page of the log, with the envelope opt-in and the active filters. */
export function logsRequestPath(page: number, level: string, source: string): string {
	const levelParam = level === 'all' ? '' : `&level=${level}`;
	const sourceParam = `&source=${encodeURIComponent(source)}`;
	return `wcpos/v1/logs?wcpos=1&_wcpos_envelope=1&per_page=${LOGS_PER_PAGE}&page=${page}${levelParam}${sourceParam}`;
}

/** Lift the route body out of the envelope and attach the page count the pager needs. */
export function unwrapLogsEnvelope<TBody extends object>(
	envelope: LogsEnvelope<TBody>
): TBody & { _totalPages: number } {
	return {
		...envelope.data,
		_totalPages: envelope._wcpos?.total_pages ?? 1,
	};
}
