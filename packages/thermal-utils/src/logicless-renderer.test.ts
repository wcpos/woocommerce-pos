/** @vitest-environment jsdom */
import { describe, expect, it } from 'vitest';

import { renderLogiclessPreview } from './logicless-renderer';

describe('renderLogiclessPreview', () => {
	it('renders Mustache data without stripping flex or grid layout CSS', () => {
		const html = renderLogiclessPreview(
			'<div style="display:flex;justify-content:space-between"><span>{{label}}</span><span>{{amount}}</span></div><section style="display:grid;grid-template-columns:1fr 320px"></section>',
			{ label: 'Subtotal', amount: '15,00 $' },
		);

		expect(html).toContain('Subtotal');
		expect(html).toContain('15,00 $');
		expect(html).toContain('display:flex');
		expect(html).toContain('justify-content:space-between');
		expect(html).toContain('display:grid');
		expect(html).toContain('grid-template-columns:1fr 320px');
	});

	it('replaces barcode marker elements with generated barcode HTML', () => {
		const html = renderLogiclessPreview(
			'<div data-barcode="code128" data-value="{{order.number}}"></div>',
			{ order: { number: '12345' } },
		);

		expect(html).toContain('data-barcode-kind="barcode"');
		expect(html).toContain('data-barcode-value="12345"');
		expect(html).toContain('<svg');
		expect(html).not.toContain('data-barcode="code128"');
	});



	it('replaces raw barcode tags with generated barcode HTML', () => {
		const html = renderLogiclessPreview(
			'<barcode type="code128" height="40">{{order.number}}</barcode><barcode type="qrcode" scale="2">{{order.payment_url}}</barcode>',
			{ order: { number: 'POS-1234', payment_url: 'https://example.test/pay' } },
		);

		expect(html).toContain('data-barcode-kind="barcode"');
		expect(html).toContain('data-barcode-value="POS-1234"');
		expect(html).toContain('data-barcode-kind="qrcode"');
		expect(html).toContain('data-barcode-value="https://example.test/pay"');
		expect(html).toContain('<svg');
		expect(html).not.toContain('<barcode');
	});

	it('strips HTML comments before Mustache renders template content', () => {
		const html = renderLogiclessPreview('<!-- {{#todo}} documentation only --><p>{{label}}</p>', { label: 'Visible' });

		expect(html).toBe('<p>Visible</p>');
	});

	it('strips unterminated HTML comments without leaving comment openers', () => {
		const html = renderLogiclessPreview('<p>{{label}}</p><!-- {{#todo}}', { label: 'Visible' });

		expect(html).toBe('<p>Visible</p>');
		expect(html).not.toContain('<!--');
	});

	it('returns a diagnostic block when Mustache rendering fails', () => {
		const html = renderLogiclessPreview('{{#broken}}Never closed', {});

		expect(html).toContain('Template rendering error');
		expect(html).toContain('color:red');
	});
});
