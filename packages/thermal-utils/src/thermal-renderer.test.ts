/** @vitest-environment jsdom */
import { describe, expect, it } from 'vitest';

import { renderThermalPreview } from './thermal-renderer';

function root(html: string): HTMLElement {
	const div = document.createElement('div');
	div.innerHTML = html;
	const first = div.firstElementChild;
	if (!first) {
		throw new Error('renderThermalPreview returned no root element');
	}
	return first as HTMLElement;
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


	it('falls back when numeric attributes are empty or whitespace', () => {
		const html = renderThermalPreview(
			'<receipt paper-width=""><image src="https://example.test/logo.png" width=" "/></receipt>',
			{},
		);

		expect(root(html).style.width).toBe('48ch');
		expect(html).toContain('16.67ch');

		const htmlWhitespacePaperWidth = renderThermalPreview(
			'<receipt paper-width=" "><image src="https://example.test/logo.png" width=" "/></receipt>',
			{},
		);

		expect(root(htmlWhitespacePaperWidth).style.width).toBe('48ch');
	});

	it('clamps negative numeric attributes to non-negative CSS values', () => {
		const html = renderThermalPreview(
			'<receipt><size width="-2">Hidden</size><feed lines="-3"/></receipt>',
			{},
		);
		const receipt = root(html);
		const size = receipt.querySelector('span') as HTMLSpanElement;
		const feed = receipt.querySelector('div') as HTMLDivElement;

		expect(size.style.fontSize).toBe('0em');
		expect(feed.style.height).toBe('0em');
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

	it('allows relative, http, https, and data image URLs', () => {
		const html = renderThermalPreview(
			'<receipt><image src="/logo.png" width="200"/><image src="http://example.test/logo.png" width="200"/><image src="https://example.test/logo.png" width="200"/><image src="data:image/png;base64,aaaa" width="200"/></receipt>',
			{},
		);
		const images = root(html).querySelectorAll('img');

		expect(Array.from(images).map((image) => image.getAttribute('src'))).toEqual([
			'/logo.png',
			'http://example.test/logo.png',
			'https://example.test/logo.png',
			'data:image/png;base64,aaaa',
		]);
	});

	it('drops unsafe image URLs instead of rendering image elements', () => {
		const html = renderThermalPreview(
			'<receipt><image src="javascript:alert(1)" width="200"/><image src="data:text/html,evil" width="200"/><image src="vbscript:msgbox(1)" width="200"/><image src="ftp://example.test/logo.png" width="200"/><image src="//example.test/logo.png" width="200"/></receipt>',
			{},
		);

		expect(root(html).querySelectorAll('img')).toHaveLength(0);
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

	it('does not render private line item meta entries', () => {
		const html = renderThermalPreview(
			'<receipt>{{#lines}}<text>{{name}}</text>{{#meta}}<text>{{key}}: {{value}}</text>{{/meta}}{{/lines}}</receipt>',
			{
				lines: [
					{
						name: 'Hoodie with Pocket',
						meta: [
							{ key: '_woocommerce_pos_data', value: '{"price":"35"}' },
							{ key: '_woocommerce_pos_uuid', value: 'ee59a549-7d74-492d-80d7-b9735d539a5b' },
							{ key: 'Gift wrap', value: 'Yes' },
						],
					},
				],
			},
		);

		expect(html).toContain('Hoodie with Pocket');
		expect(html).toContain('Gift wrap');
		expect(html).not.toContain('_woocommerce_pos_data');
		expect(html).not.toContain('_woocommerce_pos_uuid');
	});
});
