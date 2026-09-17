import { describe, expect, it } from 'vitest';

import { sanitizeReceiptDataForRendering } from './receipt-data';

describe('offline closure presentation', () => {
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
			presentation_hints: { currency_symbol: symbol, currency_position: 'left' },
			closure: { period_sales_total: '12.0000', breakdowns: { currency: 'USD' } },
		});
		expect(data.closure).toMatchObject({ period_sales_total_display: `${expected}12.00` });
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
