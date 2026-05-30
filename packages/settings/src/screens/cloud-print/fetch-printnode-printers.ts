import apiFetch from '@wordpress/api-fetch';

import type { PrintNodePrinterOption } from './add-printer-wizard';

/**
 * Fetch the PrintNode printers available to an account from its API key.
 *
 * WCPOS only exposes its `/wcpos/v1/` REST routes when the request carries the
 * WCPOS flag (`?wcpos=1` query param or `X-WCPOS` header). Without it the route
 * 404s with `rest_no_route`, so the flag MUST stay on the path.
 *
 * @param apiKey PrintNode account API key.
 * @param fetch  apiFetch implementation; injectable for testing.
 */
export async function fetchPrintNodePrinters(
	apiKey: string,
	fetch: typeof apiFetch = apiFetch
): Promise<PrintNodePrinterOption[]> {
	const res = (await fetch({
		path: 'wcpos/v1/printnode/printers?wcpos=1',
		method: 'POST',
		data: { api_key: apiKey },
	})) as { printers?: PrintNodePrinterOption[] };
	return res.printers ?? [];
}
