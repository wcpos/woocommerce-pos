import apiFetch from '@wordpress/api-fetch';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import { fetchPrintNodePrinters } from './fetch-printnode-printers';

vi.mock('@wordpress/api-fetch', () => ({
	default: vi.fn(),
}));

const apiFetchMock = apiFetch as unknown as ReturnType<typeof vi.fn>;

beforeEach(() => {
	vi.clearAllMocks();
});

describe('fetchPrintNodePrinters', () => {
	it('requests the printnode/printers route with the wcpos flag', async () => {
		// Arrange
		apiFetchMock.mockResolvedValue({ printers: [] });

		// Act — call through the default apiFetch (production path), no injected fetch.
		await fetchPrintNodePrinters('test-api-key');

		// Assert — WCPOS only registers its /wcpos/v1/ routes when the request
		// carries the wcpos flag; without it the route 404s with rest_no_route.
		// Regression guard for the "Fetch my printers" 404 (PR #1094).
		expect(apiFetchMock).toHaveBeenCalledTimes(1);
		const args = apiFetchMock.mock.calls[0][0] as {
			path: string;
			method: string;
			data: unknown;
		};
		expect(args.path).toContain('wcpos=1');
		expect(args.path).toContain('wcpos/v1/printnode/printers');
		expect(args.method).toBe('POST');
		expect(args.data).toEqual({ api_key: 'test-api-key' });
	});

	it('returns the printers from the response', async () => {
		// Arrange
		const printers = [{ id: 75507061, name: 'Brother', default: false }];
		apiFetchMock.mockResolvedValue({ printers });

		// Act
		const result = await fetchPrintNodePrinters('k');

		// Assert
		expect(result).toEqual(printers);
	});

	it('returns an empty array when the response omits printers', async () => {
		// Arrange
		apiFetchMock.mockResolvedValue({});

		// Act
		const result = await fetchPrintNodePrinters('k');

		// Assert
		expect(result).toEqual([]);
	});
});
