import { describe, it, expect } from 'vitest';

import { generateBarcodeSvg } from './generate-barcode-svg';

describe('generateBarcodeSvg', () => {
	// Every symbology WCPOS offers (mirrors the server-side picqer map in
	// Barcode_Image.php) with a payload valid for that symbology.
	const cases: Array<[string, string]> = [
		['code128', 'WCPOS-123'],
		['code39', 'WCPOS123'],
		['code93', 'WCPOS123'],
		['ean13', '012345678905'],
		['ean8', '9638507'],
		['upca', '01234567890'],
		['upce', '0123456'],
		['codabar', 'A123456B'],
		['itf', '012345678905'],
	];

	it.each(cases)('renders %s as its own symbology, not a Code 128 fallback', (type, value) => {
		const html = generateBarcodeSvg(value, { type, kind: 'barcode' });
		expect(html).toContain('<svg');
		expect(html).not.toContain('data-barcode-error');

		if (type !== 'code128') {
			// A wrong/missing map entry would silently fall back to Code 128 and
			// still render — so assert the output differs from the Code 128
			// rendering of the same value. Different symbology = different bars.
			const asCode128 = generateBarcodeSvg(value, { type: 'code128', kind: 'barcode' });
			expect(html).not.toBe(asCode128);
		}
	});

	it('renders a QR code without a human-readable text line', () => {
		const html = generateBarcodeSvg('https://wcpos.com', { type: 'qr', kind: 'qrcode' });
		expect(html).toContain('<svg');
		expect(html).toContain('data-barcode-kind="qrcode"');
		expect(html).not.toContain('data-barcode-error');
	});

	it('falls back to Code 128 for an unknown type instead of throwing', () => {
		const html = generateBarcodeSvg('HELLO', { type: 'not-a-real-type', kind: 'barcode' });
		expect(html).toContain('<svg');
		expect(html).not.toContain('data-barcode-error');
	});

	it('labels errors with the merchant-facing type, not the internal encoder name', () => {
		// Codabar requires A–D start/stop chars; this value is invalid on purpose.
		const html = generateBarcodeSvg('12345', { type: 'codabar', kind: 'barcode' });
		expect(html).toContain('data-barcode-error');
		// The summary line uses the type the merchant chose ("codabar"), not the
		// bwip-js encoder name ("rationalizedCodabar") that spec.bcid resolves to.
		expect(html).toContain('Invalid codabar barcode value');
		expect(html).not.toContain('Invalid rationalizedcodabar barcode value');
	});

	it('returns an empty string for an empty value', () => {
		expect(generateBarcodeSvg('   ', { type: 'code128' })).toBe('');
	});
});
