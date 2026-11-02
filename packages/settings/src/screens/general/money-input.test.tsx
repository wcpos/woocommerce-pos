import * as React from 'react';

import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { MoneyInput } from '@wcpos/ui';

describe('MoneyInput', () => {
	it('puts the currency inside the group and forwards decimal input props', () => {
		const html = renderToStaticMarkup(
			<MoneyInput symbol="€" aria-label="Float" defaultValue="200.00" required />
		);
		expect(html).toContain('€</span><input');
		expect(html).toContain('inputMode="decimal"');
		expect(html).toContain('pattern="\\d+(?:\\.\\d+)?"');
		expect(html).toContain('aria-label="Float"');
		expect(html).toContain('value="200.00"');
		expect(html).toContain('required=""');
	});
});
