import { formatCopyPayload } from '../format-copy-payload';

describe('formatCopyPayload', () => {
	it('formats entry without context', () => {
		const payload = formatCopyPayload({
			timestamp: '2026-04-23T14:58:01+00:00',
			level: 'critical',
			message: 'Payment gateway "Stripe Terminal" returned fatal error',
			context: '',
		});

		expect(payload).toMatch(/^\[.+\]  CRITICAL\n/);
		expect(payload).toContain('Payment gateway "Stripe Terminal" returned fatal error');
		expect(payload).not.toContain('Context:');
		expect(payload).not.toContain('```');
	});

	it('includes context block when context is present', () => {
		const payload = formatCopyPayload({
			timestamp: '2026-04-23T14:58:01+00:00',
			level: 'error',
			message: 'boom',
			context: '{"gateway":"stripe_terminal"}',
		});

		expect(payload).toContain('Context:');
		expect(payload).toContain('{"gateway":"stripe_terminal"}');
	});

	it('uses local-time formatted header, not UTC ISO', () => {
		const payload = formatCopyPayload({
			timestamp: '2026-04-23T14:58:01+00:00',
			level: 'info',
			message: 'x',
			context: '',
		});
		const firstLine = payload.split('\n')[0];
		expect(firstLine).not.toMatch(/T\d\d:\d\d:\d\d/);
	});
});
