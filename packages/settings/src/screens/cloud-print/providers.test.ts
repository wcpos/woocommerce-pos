import { describe, expect, it } from 'vitest';

import { PROVIDERS, getProvider } from './providers';

import type { CloudProvider } from '../../hooks/use-cloud-print-settings';

describe('cloud-print providers metadata', () => {
	it('exposes metadata for exactly the three providers', () => {
		const ids: CloudProvider[] = ['star-cloudprnt', 'epson-sdp', 'printnode'];
		expect(Object.keys(PROVIDERS).sort()).toEqual([...ids].sort());
	});

	it('describes Star CloudPRNT', () => {
		const star = PROVIDERS['star-cloudprnt'];
		expect(star.label).toBe('Star CloudPRNT');
		expect(star.badge.mark).toBe('★');
		expect(star.isPolling).toBe(true);
		expect(star.pollEndpoint).toBe('cloudprnt');
	});

	it('describes Epson Server Direct Print', () => {
		const epson = PROVIDERS['epson-sdp'];
		expect(epson.label).toBe('Epson Server Direct Print');
		expect(epson.badge.mark).toBe('E');
		expect(epson.isPolling).toBe(true);
		expect(epson.pollEndpoint).toBe('epson-sdp');
	});

	it('describes PrintNode', () => {
		const printnode = PROVIDERS['printnode'];
		expect(printnode.label).toBe('PrintNode');
		expect(printnode.badge.mark).toBe('PN');
		expect(printnode.isPolling).toBe(false);
		expect(printnode.pollEndpoint).toBeNull();
	});

	it('uses wcpos:-prefixed Tailwind utilities with white text for every badge', () => {
		for (const id of Object.keys(PROVIDERS) as CloudProvider[]) {
			const { className } = PROVIDERS[id].badge;
			const utilities = className.split(/\s+/).filter(Boolean);
			expect(utilities.length).toBeGreaterThan(0);
			for (const utility of utilities) {
				expect(utility.startsWith('wcpos:')).toBe(true);
			}
			expect(className).toContain('wcpos:text-white');
		}
	});

	it('getProvider returns the same entry as PROVIDERS lookup', () => {
		expect(getProvider('star-cloudprnt')).toBe(PROVIDERS['star-cloudprnt']);
		expect(getProvider('printnode')).toBe(PROVIDERS['printnode']);
	});
});
