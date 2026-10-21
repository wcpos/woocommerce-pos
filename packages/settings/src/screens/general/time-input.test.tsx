import * as React from 'react';

import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { TimeInput } from '@wcpos/ui';

describe('TimeInput', () => {
	it('uses the browser time picker and forwards TextInput props', () => {
		const html = renderToStaticMarkup(
			<TimeInput aria-label="Close at" defaultValue="17:30" disabled />
		);
		expect(html).toContain('type="time"');
		expect(html).toContain('value="17:30"');
		expect(html).toContain('aria-label="Close at"');
		expect(html).toContain('disabled=""');
	});
});
