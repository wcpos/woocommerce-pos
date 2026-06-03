import { describe, expect, it } from 'vitest';

import { PROVIDERS, getProvider, templateOptionsForProvider } from './providers';

import type { CloudProvider } from '../../hooks/use-cloud-print-settings';
import type { TemplateEngine } from '../../hooks/use-receipt-templates';

describe('cloud-print providers metadata', () => {
	it('has metadata for star-online (push provider)', () => {
		const meta = PROVIDERS['star-online'];
		expect(meta.isPolling).toBe(false);
		expect(meta.pollEndpoint).toBeNull();
	});

	it('PROVIDERS covers every CloudProvider exactly once', () => {
		const ids = Object.keys(PROVIDERS) as CloudProvider[];
		expect(new Set(ids).size).toBe(ids.length);
		expect(ids).toEqual(expect.arrayContaining(['printnode', 'star-online', 'star-cloudprnt', 'epson-sdp']));
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

describe('templateOptionsForProvider', () => {
	const opts: { value: string; label: string; engine: TemplateEngine }[] = [
		{ value: '1', label: 'A', engine: 'thermal' },
		{ value: '2', label: 'B', engine: 'logicless' },
		{ value: '3', label: 'C', engine: 'legacy-php' },
	];

	it('keeps only thermal templates for Star CloudPRNT (direct polling)', () => {
		expect(templateOptionsForProvider(opts, 'star-cloudprnt')).toEqual([
			{ value: '1', label: 'A', engine: 'thermal' },
		]);
	});

	it('keeps only thermal templates for Epson SDP (direct polling)', () => {
		expect(templateOptionsForProvider(opts, 'epson-sdp')).toEqual([
			{ value: '1', label: 'A', engine: 'thermal' },
		]);
	});

	it('keeps all templates for PrintNode (push provider)', () => {
		expect(templateOptionsForProvider(opts, 'printnode')).toEqual(opts);
	});

	it('keeps only thermal templates for Star Online (push provider)', () => {
		expect(templateOptionsForProvider(opts, 'star-online')).toEqual([
			{ value: '1', label: 'A', engine: 'thermal' },
		]);
	});
});
