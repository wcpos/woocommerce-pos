import apiFetch from '@wordpress/api-fetch';

import type { StarDeviceOption } from './add-printer-wizard';

/**
 * Fetch the stario.online devices for a CloudPRNT URL + API key via the WCPOS
 * proxy (the browser never calls Star directly). The `?wcpos=1` flag must stay
 * on the path or the route 404s with `rest_no_route`.
 */
export async function fetchStarDevices(
	cloudprntUrl: string,
	apiKey: string,
	fetch: typeof apiFetch = apiFetch
): Promise<StarDeviceOption[]> {
	const res = (await fetch({
		path: 'wcpos/v1/star-online/devices?wcpos=1',
		method: 'POST',
		data: { cloudprnt_url: cloudprntUrl, api_key: apiKey },
	})) as { devices?: StarDeviceOption[] };
	return res.devices ?? [];
}
