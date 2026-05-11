/** @vitest-environment jsdom */
import { describe, expect, it } from 'vitest';

import { renderThermalPreview } from './thermal-renderer';

function root(html: string): HTMLElement {
	const div = document.createElement('div');
	div.innerHTML = html;
	return div.firstElementChild as HTMLElement;
}

describe('renderThermalPreview canonical parity', () => {
	it('renders the receipt paper width in ch and keeps star columns aligned', () => {
		const html = renderThermalPreview(
			'<receipt paper-width="48"><row><col width="*">Subtotal</col><col width="14" align="right">$12.34</col></row></receipt>',
			{},
		);

		expect(root(html).style.width).toBe('48ch');
		expect(html).toContain('flex: 0 0 34ch');
		expect(html).toContain('flex: 0 0 14ch');
	});

	it('renders single, dashed, dotted, and double divider styles', () => {
		const html = renderThermalPreview(
			'<receipt><line/><line style="dashed"/><line style="dotted"/><line style="double"/></receipt>',
			{},
		);

		expect(html).toContain('border-top: 1px solid #000');
		expect(html).toContain('border-top: 1px dashed #000');
		expect(html).toContain('border-top: 1px dotted #000');
		expect(html).toContain('border-top: 3px double #000');
	});

	it('constrains barcode, qrcode, and image previews by active paper width', () => {
		const html = renderThermalPreview(
			'<receipt paper-width="32"><barcode type="code128" height="40">123456</barcode><qrcode size="4">https://example.test</qrcode><image src="https://example.test/logo.png" width="200"/></receipt>',
			{},
		);

		expect(html).toContain('data-barcode-kind="barcode"');
		expect(html).toContain('data-barcode-value="123456"');
		expect(html).toContain('data-barcode-kind="qrcode"');
		expect(html).toContain('width: min(100%,');
		expect(html).toContain('ch); height: auto');
		expect(html).not.toContain('max-width: 200px');
	});

	it('drops unsafe image URLs instead of embedding javascript/data protocols', () => {
		const html = renderThermalPreview(
			'<receipt><image src="javascript:alert(1)" width="200"/><image src="data:text/html,evil" width="200"/></receipt>',
			{},
		);

		expect(html).not.toContain('<img');
		expect(html).not.toContain('javascript:');
		expect(html).not.toContain('data:text/html');
	});

	it('renders barcode errors with diagnostic text rather than console warnings', () => {
		const html = renderThermalPreview(
			'<receipt><barcode type="ean13">not-an-ean</barcode></receipt>',
			{},
		);

		expect(html).toContain('data-barcode-error="true"');
		expect(html).toContain('Barcode error');
		expect(html).toContain('not-an-ean');
	});
});
