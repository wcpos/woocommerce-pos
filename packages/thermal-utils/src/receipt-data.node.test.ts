// @vitest-environment node
import { describe, expect, it } from 'vitest';

import { sanitizeReceiptDataForRendering } from './receipt-data';

describe('offline closure presentation without a document', () => {
	it.each([
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
});
