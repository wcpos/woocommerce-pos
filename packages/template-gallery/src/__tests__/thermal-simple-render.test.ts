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
	lines: [
		{
			name: 'T-Shirt',
			sku: 'TSH-001',
			qty: 2,
			unit_price_incl_display: '$19.99',
			line_total_incl_display: '$39.98',
		},
		{
			name: 'Coffee Mug',
			sku: 'MUG-001',
			qty: 1,
			unit_price_incl_display: '$9.99',
			line_total_incl_display: '$9.99',
		},
	],
	fees: [],
	shipping: [],
	discounts: [],
	tax_summary: [{ label: 'Sales Tax', rate: 8, tax_amount_display: '$3.99' }],
	totals: {
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
			expect(html).toContain('Cash');
			expect(html).toContain('$6.04');
			expect(html).toContain('Thank you for your purchase!');
			expect(html).toContain('My Store Pty Ltd');
			expect(html).toContain('<svg');
		});
	}
});
