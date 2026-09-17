import fs from 'node:fs';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

import { renderLogiclessPreview } from './logicless-renderer';
import { renderThermalPreview } from './thermal-renderer';

const gallery = path.resolve(__dirname, '../../../templates/gallery');
const fixture = JSON.parse(
	fs.readFileSync(path.join(gallery, 'preview-data/closure.json'), 'utf8')
);

describe.each([
	['closure-default.html', renderLogiclessPreview],
	['thermal-closure-80mm.xml', renderThermalPreview],
] as const)('%s', (file, render) => {
	const template = fs.readFileSync(path.join(gallery, file), 'utf8');

	it('renders offline tender maps including non-cash tenders and missing counts', () => {
		const data = structuredClone(fixture);
		data.closure.expected.voucher = '19.0000';
		const html = render(template, data);
		for (const text of [
			'Closure 42',
			'Main register',
			'178.0000',
			'-2.0000',
			'voucher',
			'19.0000',
			'Petty cash',
			'Voided',
			'VAT 20%',
			'5250.0000',
		]) {
			expect(html).toContain(text);
		}
		expect(html).not.toContain('undefined');
		expect(data.closure).not.toHaveProperty('tenders');
	});

	it('renders an X-report copy and omits corrections', () => {
		const data = structuredClone(fixture);
		data.fiscal.is_x_report = true;
		data.fiscal.is_reprint = true;
		data.fiscal.reprint_count = 2;
		data.order = { printed: { datetime: '2026-09-11 18:00' } };
		data.closure.corrections = [{ reason: 'DO NOT PRINT THIS' }];
		const html = render(template, data);
		expect(html).toContain('X-report');
		expect(html).toContain('COPY 2');
		expect(html).toContain('2026-09-11 18:00');
		expect(html).not.toContain('Closure 42');
		expect(html).not.toContain('DO NOT PRINT THIS');
	});
});
