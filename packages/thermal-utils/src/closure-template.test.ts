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

	it('renders formatted document money, local dates and labels', () => {
		const data = structuredClone(fixture);
		const html = render(template, data);
		for (const text of [
			'Closure 42',
			'Main register',
			'$178.00',
			'-$2.00',
			'Paid out',
			'Sep 11, 2026, 10:00',
			'12:00',
			'Petty cash',
			'Voided',
			'VAT 20%',
			'$5,250.00',
		]) {
			expect(html).toContain(text);
		}
		expect(html).not.toContain('undefined');
		expect(html).not.toMatch(/\d+\.\d{4}|paid_out|2026-09-11 08:00:00|UTC/);
		expect(data).toEqual(fixture);
		if (file === 'closure-default.html') {
			expect(html).toContain('-$2.00 Short');
			expect(html).toContain('$0.00 Exact');
		}
	});

	it('prints tender labels instead of slugs', () => {
		const data = structuredClone(fixture);
		data.closure.tenders[0].label = 'Cash drawer';
		data.closure.tenders[1].label = 'Credit card';
		const html = render(template, data);
		expect(html).toContain('Cash drawer');
		expect(html).toContain('Credit card');
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

it('keeps thermal variance cells separate from translated non-exact details at 42 columns', () => {
	const template = fs.readFileSync(path.join(gallery, 'thermal-closure-80mm.xml'), 'utf8');
	const data = structuredClone(fixture);
	data.closure.tenders.push({
		name: 'voucher', label: 'Voucher', expected_display: '$9.00', counted_display: '$10.00',
		variance_display: '$1.00', variance_label: 'Over', has_variance: true,
		variance_absolute_display: '$1.00',
	});
	data.closure.tenders[0].variance_label = 'Faltante';
	const root = document.createElement('div');
	root.innerHTML = renderThermalPreview(template.replace('paper-width="48"', 'paper-width="42"'), data);
	const rows = Array.from(root.querySelectorAll('div')).filter((el) => el.style.display === 'flex');
	const cash = rows.find((row) => row.firstElementChild?.textContent === 'Cash')!;
	expect(cash).toBeDefined();
	expect(cash.lastElementChild?.textContent).toBe('-$2.00');
	for (const cell of Array.from(cash.children)) {
		expect(cell.textContent!.length).toBeLessThanOrEqual(10);
	}
	expect(root.textContent).toContain('Cash Faltante $2.00');
	expect(root.textContent).toContain('Voucher Over $1.00');
	expect(root.textContent).not.toContain('Exact');
});
