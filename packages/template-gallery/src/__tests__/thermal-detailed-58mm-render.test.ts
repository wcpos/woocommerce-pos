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
		policies_and_conditions: 'Returns within 30 days with receipt.',
		footer_imprint: 'My Store Pty Ltd',
		opening_hours: 'Mon–Fri 9–5',
		opening_hours_notes: 'Closed on public holidays',
	},
	cashier: { name: 'Admin' },
	customer: {
		name: 'Jane Doe',
		billing_address: {
			address_1: '99 Buyer Lane',
			city: 'Buyertown',
			postcode: '94000',
			state: 'CA',
			country: 'US',
			email: 'jane@example.com',
			phone: '+1 555 0000',
		},
		shipping_address: {
			first_name: 'Jane',
			last_name: 'Doe',
			address_1: '99 Ship Lane',
			city: 'Shipville',
			postcode: '94001',
			country: 'US',
		},
		tax_ids: [{ type: 'us_ein', value: '98-7654321', label: 'EIN' }],
	},
	order: {
		number: '1234',
		created: { datetime: '2026-05-08 14:30' },
		customer_note: 'Please gift wrap.',
		status_label: 'Completed',
	},
	fiscal: { document_label: 'Tax Invoice' },
	lines: [
		{
			name: 'T-Shirt',
			sku: 'TSH-001',
			qty: 2,
			unit_price_incl_display: '$19.99',
			line_total_incl_display: '$39.98',
		},
	],
	fees: [],
	shipping: [],
	discounts: [],
	tax_summary: [
		{
			label: 'Sales Tax',
			rate: 8,
			tax_amount_display: '$3.20',
			taxable_amount_excl_display: '$40.00',
			taxable_amount_incl_display: '$43.20',
		},
	],
	totals: {
		subtotal_excl_display: '$40.00',
		total_excl_display: '$40.00',
		tax_total_display: '$3.20',
		total_incl_display: '$43.20',
		change_total: '6.80',
		change_total_display: '$6.80',
	},
	refunds: [],
	payments: [
		{
			method_title: 'Cash',
			amount_display: '$50.00',
			transaction_id: 'TX-001',
			tendered: '50',
			tendered_display: '$50.00',
			change: '6.80',
			change_display: '$6.80',
		},
	],
	i18n: {
		order: 'Order',
		date: 'Date',
		cashier: 'Cashier',
		customer_tax_id: 'Customer Tax ID',
		bill_to: 'Bill To',
		ship_to: 'Ship To',
		item: 'Item',
		subtotal_excl_tax: 'Subtotal (excl. tax)',
		total_excl: 'Total (excl.)',
		total_tax: 'Total Tax',
		total: 'Total',
		discount: 'Discount',
		tendered: 'Tendered',
		change: 'Change',
		paid: 'Paid',
		reference: 'Ref',
		customer_note: 'Customer Note',
		tax_summary: 'Tax Summary',
		taxable_excl_short: 'Taxable excl.',
		taxable_incl_short: 'Taxable incl.',
		returned_items: 'Returned Items',
		total_refunded: 'Total Refunded',
		net_total: 'Net Total',
		refunded: 'Refunded',
		terms_and_conditions: 'Terms & Conditions',
		opening_hours: 'Opening Hours',
		thank_you_purchase: 'Thank you for your purchase!',
		tax_invoice: 'Tax Invoice',
	},
};

describe('thermal-detailed-58mm renders all detailed sections', () => {
	it('renders every key receipt section with sample data', () => {
		const xml = fs.readFileSync(path.join(galleryDir, 'thermal-detailed-58mm.xml'), 'utf8');
		const html = renderThermalPreview(xml, sampleData);

		// Store identity
		expect(html).toContain('logo.png');
		expect(html).toContain('My Store');
		expect(html).toContain('123 Main St');
		expect(html).toContain('+1 (555) 123-4567');
		expect(html).toContain('hello@mystore.com');
		expect(html).toContain('EIN');

		// Document title + status
		expect(html).toContain('Tax Invoice');
		expect(html).toContain('Completed');

		// Order info
		expect(html).toContain('1234');
		expect(html).toContain('Admin');

		// Bill-to and ship-to
		expect(html).toContain('Bill To');
		expect(html).toContain('Jane Doe');
		expect(html).toContain('99 Buyer Lane');
		expect(html).toContain('Ship To');
		expect(html).toContain('99 Ship Lane');

		// Items
		expect(html).toContain('T-Shirt');
		expect(html).toContain('TSH-001');

		// Tax summary (stacked taxable rows)
		expect(html).toContain('Tax Summary');
		expect(html).toContain('Sales Tax');
		expect(html).toContain('Taxable excl.');
		expect(html).toContain('Taxable incl.');
		expect(html).toContain('$40.00');
		expect(html).toContain('$43.20');

		// Totals
		expect(html).toContain('Subtotal (excl. tax)');
		expect(html).toContain('Total (excl.)');
		expect(html).toContain('Total Tax');
		expect(html).toContain('$3.20');

		// Customer note
		expect(html).toContain('Please gift wrap');

		// Payments + change
		expect(html).toContain('Cash');
		expect(html).toContain('TX-001');
		expect(html).toContain('$6.80');

		// Footer blocks
		expect(html).toContain('<svg');
		expect(html).toContain('Thank you for your purchase!');
		expect(html).toContain('Returns within 30 days');
		expect(html).toContain('My Store Pty Ltd');
		expect(html).toContain('Opening Hours');
		expect(html).toContain('Closed on public holidays');
	});
});
