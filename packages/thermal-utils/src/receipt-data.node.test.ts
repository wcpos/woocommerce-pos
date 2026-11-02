// @vitest-environment node
import { describe, expect, it } from 'vitest';

import { sanitizeReceiptDataForRendering } from './receipt-data';

describe('offline closure presentation without a document', () => {
	it.each([
		['&euro;', '€'],
		['&pound;', '£'],
		['&yen;', '¥'],
		['&fnof;', 'ƒ'],
		['&curren;', '¤'],
		['&cent;', '¢'],
		['&#8377;', '₹'],
		['&#x20B9;', '₹'],
		['&amp;', '&'],
		['&unknown;', '&unknown;'],
		['&constructor;', '&constructor;'],
		['&Agrave;&alpha;&OElig;&trade;', 'ÀαŒ™'],
		['&amp;&lt;&gt;&quot;&#39;&nbsp;', '&<>"\'\u00a0'],
		['&#36;&#x20AC;&#X1F4B0;', '$€💰'],
		['&amp;#36;', '&#36;'],
	])('decodes symbol %s without browser globals', (symbol, expected) => {
		expect(typeof document).toBe('undefined');
		const data = sanitizeReceiptDataForRendering({
			presentation_hints: { currency_symbol: symbol, currency_position: 'left' },
			closure: { period_sales_total: '12.0000' },
		});
		expect(data.closure).toMatchObject({ period_sales_total_display: `${expected}12.00` });
	});

	it('renders an EUR store symbol with an explicit position without browser globals', () => {
		expect(typeof document).toBe('undefined');
		const data = sanitizeReceiptDataForRendering({
			order: { currency: 'EUR' },
			presentation_hints: {
				locale: 'en_US',
				currency_symbol: '&euro;',
				currency_position: 'left',
				price_num_decimals: 2,
			},
			closure: { period_sales_total: '12.0000', breakdowns: { currency: 'EUR' } },
		});
		expect(data.closure).toMatchObject({ period_sales_total_display: '€12.00' });
	});
});
