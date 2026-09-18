import { describe, expect, it, vi } from 'vitest';

import { sanitizeReceiptDataForRendering } from './receipt-data';

describe('offline closure presentation', () => {
	it('prefers recorded money hints over live store hints, including the recorded symbol', () => {
		const moneyFormat = {
			price_num_decimals: 4,
			price_decimal_separator: ',',
			price_thousand_separator: '.',
			currency_position: 'right_space',
			currency_symbol: 'EUR&#x20AC;',
		};
		const data = sanitizeReceiptDataForRendering({
			order: { currency: 'USD' },
			store: { price_decimals: 2 },
			presentation_hints: {
				locale: 'en-US',
				price_num_decimals: 2,
				price_decimal_separator: '.',
				price_thousand_separator: ',',
				currency_position: 'left',
				currency_symbol: '$',
			},
			closure: {
				period_sales_total: '1234.5678',
				counted: { cash: '1234.5678' },
				breakdowns: { currency: 'EUR', money_format: moneyFormat },
			},
		});
		expect(data.closure).toMatchObject({
			period_sales_total_display: '1.234,5678 EUR€',
			tenders: [{ counted_display: '1.234,5678 EUR€' }],
		});
		expect(data.presentation_hints).toMatchObject(moneyFormat);
	});
	it.each(['cash', '1.2.3', '0xFF', '1e3'])(
		'preserves non-decimal amount %s as raw display',
		(sales) => {
			const data = sanitizeReceiptDataForRendering({
				closure: { breakdowns: { payment_methods: { cash: { sales } } } },
			});
			expect(data.closure).toMatchObject({
				breakdowns: { payment_methods: [{ sales, sales_display: sales }] },
			});
		}
	);

	it.each([
		['left', '₮1.234,50'],
		['left_space', '₮ 1.234,50'],
		['right', '1.234,50₮'],
		['right_space', '1.234,50 ₮'],
		[undefined, '₮1.234,50'],
	])('formats a rejected currency code with store hints at %s', (position, expected) => {
		const data = sanitizeReceiptDataForRendering({
			order: { currency: 'USDT' },
			presentation_hints: {
				locale: 'de_DE',
				currency_symbol: '&#x20AE;',
				currency_position: position,
				price_num_decimals: 2,
				price_thousand_separator: '.',
				price_decimal_separator: ',',
			},
			closure: {
				period_sales_total: '1234.5000',
				period_refunds_total: '-1234.5000',
				breakdowns: { currency: 'USDT' },
			},
		});
		expect(data.closure).toMatchObject({
			period_sales_total_display: expected,
			period_refunds_total_display: `-${expected}`,
		});
	});

	it.each(['Recorded register', '', undefined])(
		'overlays recorded register name %j without changing other fields',
		(register_name) => {
			const input = {
				register: { id: 7, name: 'Current register' },
				closure: { breakdowns: { labels: { register_name } } },
			};
			expect(sanitizeReceiptDataForRendering(input).register).toEqual({
				id: 7,
				name: register_name ?? 'Current register',
			});
			expect(input.register.name).toBe('Current register');
		}
	);

	it('overlays the recorded store identity without replacing current presentation settings', () => {
		const input = {
			store: { name: 'Current shop', address_lines: ['New address'], locale: 'en_US' },
			closure: {
				breakdowns: { store: { name: 'Recorded shop', address_lines: ['Old address'] } },
			},
		};
		expect(sanitizeReceiptDataForRendering(input).store).toEqual({
			name: 'Recorded shop',
			address_lines: ['Old address'],
			locale: 'en_US',
		});
		expect(input.store.name).toBe('Current shop');
	});

	it.each([
		[undefined, 'Current shop', ['New address']],
		[{}, 'Current shop', ['New address']],
		[{ name: 'Recorded shop' }, 'Recorded shop', ['New address']],
		[{ address_lines: [] }, 'Current shop', []],
	])(
		'keeps current store fields missing from the snapshot %j',
		(recordedStore, name, address_lines) => {
			const data = sanitizeReceiptDataForRendering({
				store: { name: 'Current shop', address_lines: ['New address'] },
				closure: { breakdowns: { store: recordedStore } },
			});
			expect(data.store).toEqual({
				name,
				address_lines,
			});
		}
	);

	it.each([
		['USD', '&#36;', '€12.00'],
		['EUR', 'EUR&nbsp;', 'EUR\u00a012.00'],
	])(
		'formats recorded EUR using the %s store hint only when currencies match',
		(currency, symbol, expected) => {
			const data = sanitizeReceiptDataForRendering({
				order: { currency },
				presentation_hints: {
					locale: 'en_US',
					currency_symbol: symbol,
					currency_position: 'left',
				},
				closure: { period_sales_total: '12.0000', breakdowns: { currency: 'EUR' } },
			});
			expect(data.closure).toMatchObject({ period_sales_total_display: expected });
		}
	);

	it.each(['10.0000', 10, 0, true, false, null, []].map((opening_float) => ({ opening_float })))(
		'treats malformed opening float $opening_float as absent',
		({ opening_float }) => {
			const data = sanitizeReceiptDataForRendering({
				closure: { period_sales_total: '12.0000', breakdowns: { opening_float } },
			});
			expect(data.closure).toMatchObject({ period_sales_total_display: '$12.00' });
			expect((data.closure as { breakdowns: object }).breakdowns).not.toHaveProperty(
				'opening_float'
			);
		}
	);

	it('formats an object opening float without changing its recorded amounts', () => {
		const data = sanitizeReceiptDataForRendering({
			closure: {
				breakdowns: {
					opening_float: { expected: '10.0000', counted: '9.0000', variance: '-1.0000' },
				},
			},
		});
		expect(data.closure).toMatchObject({
			breakdowns: {
				opening_float: {
					expected: '10.0000',
					expected_display: '$10.00',
					counted: '9.0000',
					counted_display: '$9.00',
					variance: '-1.0000',
					variance_display: '-$1.00',
				},
			},
		});
	});

	it.each([
		['en_US', 'h', '14', '2:00 PM'],
		['en_US', 'hh', '14', '02:00 PM'],
		['en_GB', 'h', '14', '2:00 pm'],
		['en_GB', 'hh', '14', '02:00 pm'],
		['en_US', 'H', '02', '2:00'],
		['en_US', 'HH', '02', '02:00'],
	])('preserves %s ICU hour token %s padding', (locale, hour_token, hour, time) => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: { locale, hour_token, timezone: 'UTC' },
			closure: {
				opened_at_gmt: `2026-09-11 ${hour}:00:00`,
				breakdowns: {
					movements: [{ created_at_gmt: `2026-09-11 ${hour}:00:00` }],
				},
			},
		});
		expect(data.closure).toMatchObject({
			opened_at: {
				time,
				datetime: expect.stringContaining(time),
				datetime_short: expect.stringContaining(time),
				datetime_long: expect.stringContaining(time),
				datetime_full: expect.stringContaining(time),
			},
			breakdowns: { movements: [{ created_at: { time } }] },
		});
	});

	it('preserves Serbian Latin month and weekday names', () => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: { locale: 'sr_RS@latin', timezone: 'UTC' },
			closure: { opened_at_gmt: '2026-09-11 14:00:00' },
		});
		expect(data.closure).toMatchObject({
			opened_at: { month_long: 'septembar', weekday_long: 'petak' },
		});
	});

	it.each([
		['en_US', false, '14:00'],
		['en_GB', true, '02:00 pm'],
	])('uses the store clock convention in %s', (locale, hour12, time) => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: { locale, hour12, timezone: 'UTC' },
			closure: {
				opened_at_gmt: '2026-09-11 14:00:00',
				breakdowns: {
					movements: [
						{
							type: 'paid_in',
							created_at_gmt: '2026-09-11 14:00:00',
						},
					],
				},
			},
		});
		expect(data.closure).toMatchObject({
			opened_at: { time, datetime: expect.stringContaining(time) },
			breakdowns: { movements: [{ created_at: { time } }] },
		});
	});

	it.each([
		['999999999999999.9900', 2, '$999,999,999,999,999.99'],
		['999999999999999.9950', 2, '$1,000,000,000,000,000.00'],
		['-999999999999999.9950', 2, '-$1,000,000,000,000,000.00'],
		['1.0050', 2, '$1.01'],
		['9.5000', 0, '$10'],
		['0.0000', 3, '$0.000'],
	])('formats decimal %s exactly at %i places', (value, decimals, expected) => {
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: {
				locale: 'en_US',
				currency_symbol: '&#36;',
				currency_position: 'left',
				price_num_decimals: decimals,
				price_thousand_separator: ',',
				price_decimal_separator: '.',
			},
			closure: { period_sales_total: value },
		});
		expect(data.closure).toMatchObject({
			period_sales_total: value,
			period_sales_total_display: expected,
		});
	});

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
		['sr_RS@latin', 'sr-Latn-RS'],
		['sr_RS@cyrillic', 'sr-Cyrl-RS'],
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
