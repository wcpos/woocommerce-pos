import { formatCopyPayload, formatLocalTimestamp } from '../format-copy-payload';

describe('formatCopyPayload', () => {
	const UTC = { locale: 'en-US', timeZone: 'UTC' };

	it('formats entry without context', () => {
		const payload = formatCopyPayload(
			{
				timestamp: '2026-04-23T14:58:01+00:00',
				level: 'critical',
				message: 'Payment gateway "Stripe Terminal" returned fatal error',
				context: '',
			},
			UTC
		);

		expect(payload).toMatch(/^\[.+\]  CRITICAL\n/);
		expect(payload).toContain('Payment gateway "Stripe Terminal" returned fatal error');
		expect(payload).not.toContain('Context:');
		expect(payload).not.toContain('```');
	});

	it('includes context block when context is present', () => {
		const payload = formatCopyPayload(
			{
				timestamp: '2026-04-23T14:58:01+00:00',
				level: 'error',
				message: 'boom',
				context: '{"gateway":"stripe_terminal"}',
			},
			UTC
		);

		expect(payload).toContain('Context:');
		expect(payload).toContain('{"gateway":"stripe_terminal"}');
	});

	it('uses locale-formatted header, not UTC ISO', () => {
		const payload = formatCopyPayload(
			{
				timestamp: '2026-04-23T14:58:01+00:00',
				level: 'info',
				message: 'x',
				context: '',
			},
			UTC
		);
		const firstLine = payload.split('\n')[0];
		// No raw ISO "T14:58:01" pattern should remain.
		expect(firstLine).not.toMatch(/T\d\d:\d\d:\d\d/);
	});

	it('respects the provided timezone when formatting', () => {
		// 14:58 UTC is 00:58 next-day in Sydney (UTC+10). Using en-GB to get
		// predictable 24-hour output across Node versions.
		const sydney = formatLocalTimestamp('2026-04-23T14:58:01+00:00', {
			locale: 'en-GB',
			timeZone: 'Australia/Sydney',
		});
		expect(sydney).toMatch(/24 Apr 2026/);
		expect(sydney).toMatch(/00:58:01/);
	});

	it('returns the raw string for unparseable timestamps', () => {
		expect(formatLocalTimestamp('not-a-date')).toBe('not-a-date');
	});
});
