/**
 * The api-fetch `_method` shim (assets/js/api-fetch-method-param.js) is a
 * plain script that patches the shared `wp.apiFetch` instance. These tests run
 * the real shim source against the real `@wordpress/api-fetch` middleware chain
 * and inspect what reaches the fetch handler — the exact request shape a host
 * sees.
 */
import fs from 'fs';
import path from 'path';

import apiFetch, { type APIFetchOptions } from '@wordpress/api-fetch';
import { beforeAll, describe, expect, it, vi } from 'vitest';

const SHIM_SOURCE = fs.readFileSync(
	path.resolve(__dirname, '../../../../assets/js/api-fetch-method-param.js'),
	'utf8'
);

/** Execute the shim exactly as the browser would (an IIFE over `window.wp`). */
function loadShim() {
	// eslint-disable-next-line no-new-func
	new Function('window', SHIM_SOURCE)(window);
}

/** Send a request through the full middleware chain and return what the fetch handler received. */
async function sendThroughChain(options: APIFetchOptions): Promise<APIFetchOptions> {
	let captured: APIFetchOptions | undefined;
	apiFetch.setFetchHandler((received) => {
		captured = received;
		return Promise.resolve({});
	});
	await apiFetch(options);
	if (!captured) {
		throw new Error('fetch handler was not reached');
	}
	return captured;
}

/** Split a path or url into its pathname and parsed query (the chain appends `_locale=user`). */
function splitTarget(target: string | undefined) {
	const [pathname, query = ''] = (target ?? '').split('?');
	return { pathname, params: new URLSearchParams(query) };
}

beforeAll(() => {
	(window as unknown as { wp: unknown }).wp = { apiFetch };
	loadShim();
});

describe('api-fetch _method shim', () => {
	it.each(['DELETE', 'PUT', 'PATCH'] as const)(
		'sends %s as POST + ?_method=%s with no X-HTTP-Method-Override header',
		async (method) => {
			const received = await sendThroughChain({
				path: 'wcpos/v1/templates/42?wcpos=1',
				method,
			});
			const { pathname, params } = splitTarget(received.path);

			expect(received.method).toBe('POST');
			expect(pathname).toBe('wcpos/v1/templates/42');
			expect(params.get('_method')).toBe(method);
			expect(params.get('wcpos')).toBe('1');
			expect(received.headers ?? {}).not.toHaveProperty('X-HTTP-Method-Override');
		}
	);

	it('starts the query string when the path has none', async () => {
		const received = await sendThroughChain({
			path: 'wcpos/v1/templates/42',
			method: 'DELETE',
		});

		expect(received.path).toMatch(/^wcpos\/v1\/templates\/42\?_method=DELETE(&|$)/);
	});

	it('rewrites an absolute url the same way and keeps the body', async () => {
		const received = await sendThroughChain({
			url: 'https://example.test/wp-json/wcpos/v1/templates/42?wcpos=1',
			method: 'PATCH',
			data: { name: 'Renamed' },
		});
		const { pathname, params } = splitTarget(received.url);

		expect(received.method).toBe('POST');
		expect(pathname).toBe('https://example.test/wp-json/wcpos/v1/templates/42');
		expect(params.get('_method')).toBe('PATCH');
		expect(received.data).toEqual({ name: 'Renamed' });
	});

	it.each(['GET', 'POST'] as const)('leaves %s requests untouched', async (method) => {
		const received = await sendThroughChain({ path: 'wcpos/v1/templates?wcpos=1', method });
		const { pathname, params } = splitTarget(received.path);

		expect(received.method).toBe(method);
		expect(pathname).toBe('wcpos/v1/templates');
		expect(params.has('_method')).toBe(false);
		expect(received.headers ?? {}).not.toHaveProperty('X-HTTP-Method-Override');
	});

	it('registers its middleware only once when loaded twice', () => {
		const use = vi.spyOn(apiFetch, 'use');

		loadShim();

		expect(use).not.toHaveBeenCalled();
		use.mockRestore();
	});
});
