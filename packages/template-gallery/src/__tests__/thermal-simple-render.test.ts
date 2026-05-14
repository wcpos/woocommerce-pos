import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

import { renderThermalPreview } from '@wcpos/thermal-utils';

const galleryDir = path.resolve(__dirname, '../../../../templates/gallery');

const sampleData = {
	store: {
		name: 'My Store',
		address_lines: ['123 Main St', 'Anytown, CA 90210'],
		phone: '+1 (555) 123-4567',
		email: 'hello@mystore.com',
		logo: 'https://example.com/logo.png',
		tax_ids: [{ type: 'us_ein', value: '12-3456789', label: 'EIN' }],
		personal_notes: '',
		footer_imprint: 'My Store Pty Ltd',
	},
	cashier: { name: 'Admin' },
	customer: { name: 'Jane Doe' },
	order: {
		number: '1234',
		created: { datetime: '2026-05-08 14:30' },
		customer_note: '',
	},
	tax: { display_excl: true, display_incl: false },
	lines: [
		{
			name: 'T-Shirt',
			sku: 'TSH-001',
			qty: 2,
			unit_price_display: '$19.99',
			unit_price_incl_display: '$19.99',
			line_total_display: '$39.98',
			line_total_incl_display: '$39.98',
		},
		{
			name: 'Coffee Mug',
			sku: 'MUG-001',
			qty: 1,
			unit_price_display: '$9.99',
			unit_price_incl_display: '$9.99',
			line_total_display: '$9.99',
			line_total_incl_display: '$9.99',
		},
	],
	fees: [],
	shipping: [],
	discounts: [],
	has_tax_summary: true,
	tax_summary: [{ label: 'Sales Tax', rate: 8, tax_amount_display: '$3.99' }],
	totals: {
		subtotal_display: '$49.97',
		subtotal_incl_display: '$49.97',
		total_incl_display: '$53.96',
	},
	payments: [
		{
			method_title: 'Cash',
			amount_display: '$60.00',
			tendered: '60',
			tendered_display: '$60.00',
			change: '6.04',
			change_display: '$6.04',
		},
	],
	i18n: {
		order: 'Order',
		date: 'Date',
		cashier: 'Cashier',
		customer: 'Customer',
		subtotal: 'Subtotal',
		total: 'Total',
		total_tax: 'Total Tax',
		included_tax: 'Tax included',
		discount: 'Discount',
		tendered: 'Tendered',
		change: 'Change',
		paid: 'Paid',
		customer_note: 'Customer Note',
		thank_you_purchase: 'Thank you for your purchase!',
	},
};

describe('thermal simple templates render with sample data', () => {
	for (const file of ['thermal-simple-80mm.xml', 'thermal-simple-58mm.xml']) {
		it(`${file} renders all key receipt sections`, () => {
			const xml = fs.readFileSync(path.join(galleryDir, file), 'utf8');
			const html = renderThermalPreview(xml, sampleData);
			expect(html).toContain('logo.png');
			expect(html).toContain('My Store');
			expect(html).toContain('123 Main St');
			expect(html).toContain('+1 (555) 123-4567');
			expect(html).toContain('hello@mystore.com');
			expect(html).toContain('EIN');
			expect(html).toContain('1234');
			expect(html).toContain('Admin');
			expect(html).toContain('Jane Doe');
			expect(html).toContain('T-Shirt');
			expect(html).toContain('TSH-001');
			expect(html).toContain('$49.97');
			expect(html).toContain('$53.96');
			expect(html).toContain('Sales Tax');
			expect(html).not.toContain('Tax included: Sales Tax');
			expect(html).toContain('Cash');
			expect(html).toContain('$6.04');
			expect(html).toContain('Thank you for your purchase!');
			expect(html).toContain('My Store Pty Ltd');
			expect(html).toContain('<svg');
			const inclusiveHtml = renderThermalPreview(xml, {
				...sampleData,
				tax: { display_excl: false, display_incl: true },
			});
			expect(inclusiveHtml).toContain('Tax included: Sales Tax');
			expect(inclusiveHtml).not.toContain('Total Tax');
			expect(inclusiveHtml).not.toContain('Tax Summary');

			if (file === 'thermal-simple-58mm.xml') {
				const rendered = document.createElement('div');
				rendered.innerHTML = html;
				const subtotalAmount = [...rendered.querySelectorAll('span')].find(
					(span) => span.textContent === '$49.97',
				);
				expect((rendered.firstElementChild as HTMLElement).style.width).toBe('32ch');
				expect((rendered.querySelector('img') as HTMLImageElement).getAttribute('style')).toContain('width: min(100%,');
				expect(rendered.querySelector('svg')?.getAttribute('style')).toContain('width: min(100%,');
				expect(subtotalAmount?.getAttribute('style')).toContain('flex: 0 0 12ch');
			}
		});

		it(`${file} hides optional blocks and renders line discounts`, () => {
			const xml = fs.readFileSync(path.join(galleryDir, file), 'utf8');
			const edgeData = {
				...sampleData,
				store: {
					...sampleData.store,
					logo: undefined,
					phone: '',
					email: undefined,
					tax_ids: [],
					footer_imprint: undefined,
				},
				order: {
					...sampleData.order,
					customer_note: undefined,
				},
				lines: [
					{
						...sampleData.lines[0],
						name: 'Discounted Item',
						sku: 'DISC-001',
						unit_subtotal_display: '$20.00',
						unit_subtotal_incl_display: '$20.00',
						unit_price_display: '$15.00',
						unit_price_incl_display: '$15.00',
						discounts_display: '$5.00',
						discounts_incl_display: '$5.00',
						line_total_display: '$15.00',
						line_total_incl_display: '$15.00',
						discounts: [{ label: 'Promo' }],
					},
				],
			};

			const html = renderThermalPreview(xml, edgeData);
			const rendered = document.createElement('div');
			rendered.innerHTML = html;
			const renderedText = rendered.textContent ?? '';
			expect(rendered.querySelector('img')).toBeNull();
			for (const hidden of [
				'+1 (555) 123-4567',
				'hello@mystore.com',
				'EIN',
				'12-3456789',
				'Customer Note',
				'My Store Pty Ltd',
			]) {
				expect(renderedText).not.toContain(hidden);
			}
			for (const discountText of ['Discounted Item', 'DISC-001', '@ $20.00', '-$5.00', '$15.00']) {
				expect(renderedText).toContain(discountText);
			}
		});
	}
});
