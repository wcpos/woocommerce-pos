import { describe, expect, it } from 'vitest';

import { logsRequestPath, unwrapLogsEnvelope } from '../logs-request';

describe('logsRequestPath', () => {
	it('opts into the body envelope so pagination survives header-stripping proxies', () => {
		const path = logsRequestPath(1, 'all', 'all');
		const params = new URLSearchParams(path.split('?')[1]);

		expect(path.startsWith('wcpos/v1/logs?')).toBe(true);
		expect(params.get('wcpos')).toBe('1');
		expect(params.get('_wcpos_envelope')).toBe('1');
		expect(params.get('per_page')).toBe('50');
		expect(params.get('page')).toBe('1');
	});

	it('omits the level filter for "all" and includes it otherwise', () => {
		expect(
			new URLSearchParams(logsRequestPath(2, 'all', 'all').split('?')[1]).has('level')
		).toBe(false);
		expect(
			new URLSearchParams(logsRequestPath(2, 'error', 'all').split('?')[1]).get('level')
		).toBe('error');
	});

	it('url-encodes the source filter', () => {
		const params = new URLSearchParams(
			logsRequestPath(3, 'all', 'cloud print/relay').split('?')[1]
		);

		expect(params.get('source')).toBe('cloud print/relay');
		expect(params.get('page')).toBe('3');
	});
});

describe('unwrapLogsEnvelope', () => {
	it('lifts the route body and takes the page count from the envelope, not a header', () => {
		const result = unwrapLogsEnvelope({
			data: { entries: [{ message: 'a' }], has_fatal_errors: false },
			_wcpos: { total_pages: 7 },
		});

		expect(result).toEqual({
			entries: [{ message: 'a' }],
			has_fatal_errors: false,
			_totalPages: 7,
		});
	});

	it('falls back to a single page when the envelope carries no total', () => {
		expect(unwrapLogsEnvelope({ data: { entries: [] } })._totalPages).toBe(1);
		expect(unwrapLogsEnvelope({ data: { entries: [] }, _wcpos: {} })._totalPages).toBe(1);
	});
});
