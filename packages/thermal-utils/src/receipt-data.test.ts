import { describe, expect, it, vi } from 'vitest';

import { sanitizeReceiptDataForRendering } from './receipt-data';

describe('offline closure presentation', () => {
	it('creates closure date objects from raw GMT timestamps and preserves supplied dates', () => {
		const raw = {
			opened_at_gmt: '2026-09-11 08:00:00',
			closed_at_gmt: '2026-09-11 12:00:00',
		};
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: { locale: 'de_DE', timezone: 'Europe/Berlin' },
			closure: raw,
		});
		expect(data.closure).toMatchObject({
			opened_at: { datetime: '11.09.2026, 10:00', time: '10:00' },
			closed_at: { datetime: '11.09.2026, 14:00', time: '14:00' },
		});
		expect(
			sanitizeReceiptDataForRendering({
				closure: {
					...raw,
					opened_at: { datetime: 'Recorded opening' },
					closed_at: { datetime: 'Recorded closing' },
				},
			}).closure
		).toMatchObject({
			opened_at: { datetime: 'Recorded opening' },
			closed_at: { datetime: 'Recorded closing' },
		});
	});

	it('skips scalar, null and array breakdown rows before building labels or displays', () => {
		const data = sanitizeReceiptDataForRendering({
			closure: {
				counted: { cash: '10.0000' },
				breakdowns: {
					payment_methods: ['cash', null],
					tax_rates: [null, 42, []],
					movements: [false, 'paid_out', null, { type: 'paid_in', amount: '5.0000' }],
				},
			},
		});
		expect(data.closure).toMatchObject({
			tenders: [{ label: 'Cash', counted_display: '$10.00' }],
			has_payment_methods: false,
			has_tax_rates: false,
			has_movements: true,
			breakdowns: {
				payment_methods: [],
				tax_rates: [],
				movements: [{ amount_display: '$5.00' }],
			},
		});
	});

	it('uses the recorded timezone for closure and movement dates after a store change', () => {
		const closure = {
			opened_at_gmt: '2026-09-11 08:00:00',
			breakdowns: {
				timezone: 'Europe/Madrid',
				movements: [{ type: 'paid_in', created_at_gmt: '2026-09-11 08:00:00' }],
			},
		};
		for (const timezone of ['Europe/Madrid', 'America/New_York']) {
			const data = sanitizeReceiptDataForRendering({
				presentation_hints: { locale: 'de_DE', timezone },
				store: { timezone },
				closure,
			});
			expect(data.closure).toMatchObject({
				opened_at: { time: '10:00' },
				breakdowns: { movements: [{ created_at: { time: '10:00' } }] },
			});
		}
	});

	it.each([
		['pt_PT_ao90', 'pt-PT'],
		['sr_RS@latin', 'sr-RS'],
		['en-US-u-nu-arab', 'en-US-u-nu-arab'],
		['!invalid', 'en'],
	])('normalizes the WordPress locale %s before formatting', (locale, canonical) => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: { locale, timezone: 'UTC' },
			closure: {
				period_sales_total: '12.0000',
				opened_at_gmt: '2026-09-11 08:00:00',
				breakdowns: { currency: 'EUR' },
			},
		});
		expect(data.closure).toMatchObject({
			period_sales_total_display: new Intl.NumberFormat(canonical, {
				style: 'currency',
				currency: 'EUR',
				currencyDisplay: 'narrowSymbol',
			}).format(12),
			opened_at: {
				datetime: new Intl.DateTimeFormat(canonical, {
					timeZone: 'UTC',
					dateStyle: 'medium',
					timeStyle: 'short',
				}).format(new Date('2026-09-11T08:00:00Z')),
			},
		});
	});

	it('constructs no Intl formatters when display fields are already supplied', () => {
		const number = vi.spyOn(Intl, 'NumberFormat');
		const date = vi.spyOn(Intl, 'DateTimeFormat');
		try {
			sanitizeReceiptDataForRendering({
				closure: {
					opened_at_gmt: '2026-09-11 08:00:00',
					opened_at: { datetime: 'Opening' },
					closed_at: { datetime: 'Closing' },
					period_sales_total: '12.0000',
					period_sales_total_display: 'Sales',
					tenders: [{ counted: '12.0000', counted_display: 'Cash' }],
					breakdowns: {
						payment_methods: [{ sales: '12.0000', sales_display: 'Sales' }],
						movements: [
							{
								type: 'paid_in',
								amount: '2.0000',
								amount_display: 'Amount',
								created_at_gmt: '2026-09-11 08:00:00',
								created_at: { datetime: 'Movement' },
							},
						],
					},
				},
			});
			expect(number).not.toHaveBeenCalled();
			expect(date).not.toHaveBeenCalled();
		} finally {
			vi.restoreAllMocks();
		}
	});

	it('uses German presentation hints without replacing recorded or formatted values', () => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: {
				locale: 'de_DE',
				currency_symbol: '&euro;',
				currency_position: 'right_space',
				price_decimal_separator: ',',
				price_thousand_separator: '.',
				price_num_decimals: 2,
			},
			closure: {
				counted: { cash: '1234.5000' },
				variance: { cash: '-2.0000' },
				period_sales_total: '1234.5000',
				period_sales_total_display: 'already formatted',
				breakdowns: { currency: 'EUR' },
			},
		});
		expect(data.closure).toMatchObject({
			counted: { cash: '1234.5000' },
			period_sales_total_display: 'already formatted',
			tenders: [{ counted_display: '1.234,50 €', variance_display: '-2,00 €' }],
		});
	});

	it.each([
		['&#36;', '$'],
		['€', '€'],
	])('decodes the WooCommerce symbol %s once', (symbol, expected) => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: {
				currency_symbol: symbol,
				currency_position: 'left',
			},
			closure: {
				period_sales_total: '12.0000',
				breakdowns: { currency: 'USD' },
			},
		});
		expect(data.closure).toMatchObject({
			period_sales_total_display: `${expected}12.00`,
		});
	});

	it.each([
		['left', '€1234,500'],
		['left_space', '€ 1234,500'],
		['right', '1234,500€'],
		['right_space', '1234,500 €'],
	])(
		'honors explicit %s position, empty grouping and decimals over locale defaults',
		(position, expected) => {
			const data = sanitizeReceiptDataForRendering({
				presentation_hints: {
					locale: 'en_US',
					currency_position: position,
					price_decimal_separator: ',',
					price_thousand_separator: '',
					price_num_decimals: 3,
				},
				closure: {
					period_sales_total: '1234.5000',
					breakdowns: { currency: 'EUR' },
				},
			});
			expect(data.closure).toMatchObject({
				period_sales_total_display: expected,
			});
		}
	);

	it('uses store locale and decimals when there are no presentation hints', () => {
		const data = sanitizeReceiptDataForRendering({
			store: { locale: 'de-DE', price_decimals: 0 },
			closure: {
				period_sales_total: '1234.0000',
				breakdowns: { currency: 'EUR' },
			},
		});
		expect(data.closure).toMatchObject({
			period_sales_total_display: '1.234\u00a0€',
		});
	});

	it('creates movement date objects from UTC in the document timezone, preserving existing dates', () => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: { locale: 'de_DE', timezone: 'Europe/Berlin' },
			closure: {
				breakdowns: {
					movements: [
						{
							type: 'paid_in',
							created_at_gmt: '2026-09-11 23:30:00',
						},
						{
							type: 'paid_in',
							created_at_gmt: '2026-01-11 23:30:00',
						},
						{
							type: 'paid_out',
							created_at: { time: 'recorded time' },
						},
						{ type: 'paid_out', created_at_gmt: null },
					],
				},
			},
		});
		expect(data.closure).toMatchObject({
			breakdowns: {
				movements: [
					{ created_at: { time: '01:30', date_ymd: '2026-09-12' } },
					{ created_at: { time: '00:30', date_ymd: '2026-01-12' } },
					{ created_at: { time: 'recorded time' } },
					{ created_at: { time: '', date_ymd: '' } },
				],
			},
		});
	});
});
