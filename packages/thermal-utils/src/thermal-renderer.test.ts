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

	// The four tests below are paired one-for-one with the PHP side. Same markup,
	// same expected numbers: tests/includes/Templates/Thermal/Thermal_Markup_Parser_Test.php
	// (attribute resolution) and Html_Thermal_Emitter_Test.php (render bounds).
	it('clamps below-range numeric attributes up to the minimum', () => {
		const html = renderThermalPreview(
			'<receipt><size width="-2">Small</size><feed lines="-3"/><feed lines="0"/></receipt>',
			{},
		);
		const receipt = root(html);
		const size = receipt.querySelector('span') as HTMLSpanElement;
		const feeds = receipt.querySelectorAll('div');

		expect(size.style.fontSize).toBe('1em');
		expect((feeds[0] as HTMLDivElement).style.height).toBe('1.4em');
		expect((feeds[1] as HTMLDivElement).style.height).toBe('1.4em');
	});

	it('clamps above-range numeric attributes down to the maximum', () => {
		const html = renderThermalPreview(
			'<receipt paper-width="48"><size width="12">Huge</size>'
				+ '<image src="https://example.test/logo.png" width="5000"/><feed lines="500"/></receipt>',
			{},
		);

		expect(html).toContain('font-size: 8em');
		// 2000 dots of the 576-dot wide budget across 48 columns.
		expect(html).toContain('166.67ch');
		expect(html).toContain('height: 70em');
	});

	it('truncates fractional numeric attributes toward zero', () => {
		const html = renderThermalPreview(
			'<receipt><size width="2.5">Big</size><feed lines="3.5"/></receipt>',
			{},
		);

		expect(html).toContain('font-size: 2em');
		expect(html).toContain('height: 4.2em');
	});

	it('falls back for numeric literals PHP is_numeric rejects', () => {
		const html = renderThermalPreview(
			'<receipt><feed lines="0x2"/><size width="1e1">Exp</size></receipt>',
			{},
		);

		// 0x2 is not a decimal numeral, so the default of 1 line applies.
		expect(html).toContain('height: 1.4em');
		// 1e1 is, so it resolves to 10 and then clamps to the 8x printer ceiling.
		expect(html).toContain('font-size: 8em');
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

	it('sizes and labels a <barcode type="qr"> exactly like the <qrcode> it prints as', () => {
		const value = 'https://example.test';

		const asBarcodeElement = renderThermalPreview(
			`<receipt paper-width="48"><barcode type="qr" height="40">${value}</barcode></receipt>`,
			{}
		);
		const asQrcodeElement = renderThermalPreview(
			`<receipt paper-width="48"><qrcode size="4">${value}</qrcode></receipt>`,
			{}
		);

		// The printer sizes both at module scale 4 (Thermal_Markup_Parser folds
		// height 40 into size 4), so the preview must not draw one smaller.
		expect(asBarcodeElement).toContain('data-barcode-kind="qrcode"');
		expect(asBarcodeElement).not.toContain('data-barcode-kind="barcode"');
		expect(asBarcodeElement).toBe(asQrcodeElement);
	});

	it('folds a <barcode type="qrcode"> height into the printed module scale', () => {
		const value = 'https://example.test';

		const tall = renderThermalPreview(
			`<receipt paper-width="48"><barcode type="qrcode" height="60">${value}</barcode></receipt>`,
			{}
		);
		const equivalent = renderThermalPreview(
			`<receipt paper-width="48"><qrcode size="6">${value}</qrcode></receipt>`,
			{}
		);
		const huge = renderThermalPreview(
			`<receipt paper-width="48"><barcode type="qrcode" height="900">${value}</barcode></receipt>`,
			{}
		);
		const starMaximum = renderThermalPreview(
			`<receipt paper-width="48"><qrcode size="8">${value}</qrcode></receipt>`,
			{}
		);

		expect(tall).toBe(equivalent);
		expect(huge).toBe(starMaximum);
	});
});
