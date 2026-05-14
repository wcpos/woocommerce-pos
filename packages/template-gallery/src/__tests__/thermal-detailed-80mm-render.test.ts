import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

import { renderThermalPreview } from '@wcpos/thermal-utils';

const galleryDir = path.resolve(__dirname, '../../../../templates/gallery');
const xml = fs.readFileSync(path.join(galleryDir, 'thermal-detailed-80mm.xml'), 'utf8');

// tax_summary[].code is the WooCommerce tax-rate database id. The receipt must
// never print it, so the fixture uses a distinctive sentinel that cannot
// collide with prices, dates, or the order number elsewhere on the receipt.
const RATE_ID_SENTINEL = 'TAXRATEID987654';

const sampleData = {
	store: { name: 'My Store', address_lines: ['123 Main St'] },
	cashier: { name: 'Admin' },
	order: { number: '1234', created: { datetime: '2026-05-08 14:30' } },
	tax: { display_excl: true, display_incl: false },
	lines: [
		{
			name: 'T-Shirt',
			qty: 2,
			unit_price_excl_display: '$20.00',
			line_total_excl_display: '$40.00',
		},
	],
	fees: [],
	shipping: [],
	discounts: [],
	has_tax_summary: true,
	tax_summary: [
		{
			code: RATE_ID_SENTINEL,
			label: 'US',
			rate: 10,
			tax_amount_display: '$4.52',
			taxable_amount_excl_display: '$45.22',
			taxable_amount_incl_display: '$49.74',
		},
		{
			code: RATE_ID_SENTINEL,
			label: 'City',
			rate: 2,
			tax_amount_display: '$0.90',
			taxable_amount_excl_display: '$45.22',
			taxable_amount_incl_display: '$46.12',
		},
	],
	totals: {
		subtotal_excl_display: '$40.00',
		total_excl_display: '$40.00',
		tax_total: 5.42,
		tax_total_display: '$5.42',
		total_incl_display: '$50.64',
	},
	refunds: [],
	payments: [],
	// taxable_excl_short / taxable_incl_short are intentionally provided so the
	// "old layout removed" assertions below are meaningful: the values are in
	// the data, so they would render if the template still referenced them.
	i18n: {
		tax_summary: 'Tax Summary',
		included_tax: 'Tax included',
		taxable_excl_short: 'Taxable excl.',
		taxable_incl_short: 'Taxable incl.',
		total: 'Total',
	},
};

describe('thermal-detailed-80mm tax summary', () => {
	it('renders one compact line per tax rate without the internal rate id', () => {
		const html = renderThermalPreview(xml, sampleData);

		// Heading uses the additive wording in tax-exclusive display mode.
		expect(html).toContain('Tax Summary');

		// Each rate renders its label, percent, inline net base and tax amount.
		expect(html).toContain('US (10%)');
		expect(html).toContain('City (2%)');
		expect(html).toContain('@ $45.22');
		expect(html).toContain('$4.52');
		expect(html).toContain('$0.90');

		// The WooCommerce tax-rate database id must never reach the receipt.
		expect(html).not.toContain(RATE_ID_SENTINEL);

		// The old truncating second row (stacked taxable excl./incl. labels) is gone.
		expect(html).not.toContain('Taxable excl.');
		expect(html).not.toContain('Taxable incl.');
	});

	it('omits the inline net base when a rate has no taxable amount', () => {
		const html = renderThermalPreview(xml, {
			...sampleData,
			tax_summary: [
				{
					code: RATE_ID_SENTINEL,
					label: 'US',
					rate: 10,
					tax_amount_display: '$4.52',
				},
			],
		});

		expect(html).toContain('US (10%)');
		expect(html).toContain('$4.52');
		// With no taxable_amount_excl_display, the "@ <base>" fragment is guarded out.
		expect(html).not.toContain('US (10%) @');
	});

	it('uses included-tax wording when the receipt display mode is tax-inclusive', () => {
		const html = renderThermalPreview(xml, {
			...sampleData,
			tax: { display_excl: false, display_incl: true },
		});

		expect(html).toContain('Tax included');
		expect(html).not.toContain('Tax Summary');
	});
});
