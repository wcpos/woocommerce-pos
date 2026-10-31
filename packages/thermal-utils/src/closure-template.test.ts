import fs from 'node:fs';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

import { renderLogiclessPreview } from './logicless-renderer';
import { sanitizeReceiptDataForRendering } from './receipt-data';
import { renderThermalPreview } from './thermal-renderer';

const gallery = path.resolve(__dirname, '../../../templates/gallery');
const fixture = JSON.parse(
	fs.readFileSync(path.join(gallery, 'preview-data/closure.json'), 'utf8')
);

describe.each([
	['closure-default.html', renderLogiclessPreview],
	['closure-detailed.html', renderLogiclessPreview],
	['thermal-closure-58mm.xml', renderThermalPreview],
	['thermal-closure-80mm-rtl.xml', renderThermalPreview],
	['thermal-count-slip-80mm.xml', renderThermalPreview],
	['thermal-closure-80mm.xml', renderThermalPreview],
] as const)('%s', (file, render) => {
	const template = fs.existsSync(path.join(gallery, file))
		? fs.readFileSync(path.join(gallery, file), 'utf8') : '';
	const slip = file === 'thermal-count-slip-80mm.xml';


	it('prints the hand-over header and the requesting cashier with two signature rules', () => {
		const data = structuredClone(fixture);
		data.fiscal.is_handover = true;
		data.fiscal.is_x_report = true;
		const root = document.createElement('div');
		root.innerHTML = render(template, data);
		for (const text of ['Hand-over slip', 'Drawer handed over · session continues', 'Handed over by: Alex', 'Taken over by']) expect(root.textContent).toContain(text);
		for (const text of ['Closure 42', 'X-report', 'Session closed · totals reset', 'Interim report', 'Cashier signature', 'Manager signature']) expect(root.textContent).not.toContain(text);
		if (slip) expect(root.textContent).toContain('Count slip');
		if (file.endsWith('.xml')) expect(root.textContent?.match(/_{24}/g)).toHaveLength(2);
		else expect(Array.from(root.querySelectorAll('div')).filter(el => el.style.width === '240px' && el.style.borderBottom)).toHaveLength(2);
	});

	it.each([false, true])('omits hand-over additions on ordinary documents (X-report: %s)', (xreport) => {
		const data = structuredClone(fixture);
		data.fiscal.is_handover = false;
		data.fiscal.is_x_report = xreport;
		const html = render(template, data);
		expect(html).toContain(xreport ? 'X-report' : 'Closure 42');
		for (const text of ['Hand-over slip', 'Drawer handed over', 'Handed over by', 'Taken over by']) expect(html).not.toContain(text);
	});

	it('orders the management blocks and keeps count slips free of sales figures', () => {
		const root = document.createElement('div');
		root.innerHTML = render(template, fixture);
		const headings = Array.from(root.querySelectorAll('h2, strong > div')).map(el => el.textContent);
		if (slip) {
			for (const label of ['Sales', 'Tax rates', 'Payment method', 'Items sold']) expect(root.textContent).not.toContain(label);
		} else {
			const positions = ['Sales', 'Tax rates', 'Tenders', 'Opening float'].map(label => headings.indexOf(label));
			positions.forEach(position => expect(position).toBeGreaterThanOrEqual(0));
			expect(positions).toEqual([...positions].sort((a, b) => a - b));
			const usd = structuredClone(fixture);
			usd.closure.breakdowns.discounts.amount_display = '$12.50';
			expect(render(template, usd)).toContain('$12.50');
		}
	});

	it('prints denomination amounts and signature lines only in the detailed formats', () => {
		const html = render(template, fixture);
		if (slip || file === 'closure-detailed.html') {
			for (const label of ['£100.00', '£50.00', '£20.00', '£8.00', 'Cashier signature', 'Manager signature']) expect(html).toContain(label);
		} else expect(html).not.toContain('Count by denomination');
	});

	it('omits optional additions on older closures and guards record hashes', () => {
		const data = structuredClone(fixture);
		for (const key of ['discounts', 'items_sold', 'average_sale_display', 'drawer_openings', 'variance_note', 'denominations']) delete data.closure.breakdowns[key];
		for (const key of ['has_discounts', 'has_variance_note', 'has_denominations', 'net_sales_display']) delete data.closure[key];
		const html = render(template, data);
		for (const label of ['Discounts', 'Items sold', 'Average sale', 'Drawer openings', 'Difference note', 'Count by denomination', 'Net sales', 'WCPOS record hash']) expect(html).not.toContain(label);
		expect(html).toContain('£178.00');
		data.fiscal.hash = 'hash-123';
		if (!slip) expect(render(template, data)).toContain('hash-123');
	});

	it('renders formatted document money, local dates and labels', () => {
		const data = structuredClone(fixture);
		const html = render(template, data);
		for (const text of [
			'Closure 42',
			'Main register',
			'£178.00',
			'-£2.00',
			'Paid out',
			'Sep 11, 2026, 19:00',
			'12:00',
			'Petty cash',
			'Voided',
			...(slip ? [] : ['VAT 20%', '£5,250.00', '£12.50', '47']),
			'Session closed · totals reset',
			'Management report, not a fiscal document',
			'Two £5 notes found under the drawer',
		]) {
			expect(html).toContain(text);
		}
		expect(html).not.toContain('undefined');
		expect(html).not.toMatch(/\d+\.\d{4}|paid_out|2026-09-11 08:00:00|UTC/);
		expect(data).toEqual(fixture);
		if (file === 'closure-default.html') {
			expect(html).toContain('-£2.00 Short');
			expect(html).toContain('£0.00 Exact');
		}
	});

	it('renders legacy offline money maps while preserving server-formatted new fields', () => {
		const data = structuredClone(fixture);
		delete data.closure.tenders;
		const stripDisplays = (value: Record<string, unknown>) => {
			for (const key of Object.keys(value)) {
				if (key.endsWith('_display') && !['net_sales_display', 'average_sale_display'].includes(key)) delete value[key];
				else if (!['discounts', 'denominations'].includes(key) && value[key] && typeof value[key] === 'object')
					stripDisplays(value[key] as Record<string, unknown>);
			}
		};
		stripDisplays(data.closure);
		data.order = { ...data.order, currency: 'USD' };
		expect(render(template, data)).toBe(render(template, fixture));
	});

	it('omits absent sections including the tax-rate heading on a live X-report', () => {
		const data = structuredClone(fixture);
		data.fiscal.is_x_report = true;
		for (const key of ['tax_rates', 'payment_methods', 'movements']) {
			delete data.closure.breakdowns[key];
			data.closure[`has_${key}`] = false;
		}
		data.closure.has_sales = false;
		data.closure.has_perpetual = false;
		const html = render(template, data);
		for (const text of [
			'Tax rates',
			'Payment method',
			'Cash movements',
			'Period sales',
			'Perpetual totals',
		]) {
			expect(html).not.toContain(text);
		}
	});

	it('keeps available live transaction counts without printing absent period totals', () => {
		const data = structuredClone(fixture);
		data.fiscal.is_x_report = true;
		delete data.closure.has_sales;
		for (const key of ['period_sales_total', 'period_refunds_total']) {
			delete data.closure[key];
			delete data.closure[`${key}_display`];
		}
		const html = render(template, data);
		if (!slip) expect(html).toContain('Transactions');
		expect(html).toContain('Alex');
		expect(html).not.toContain('Period sales');
		expect(html).not.toContain('Period refunds');
	});

	it('uses translated headings rather than English literals', () => {
		const data = structuredClone(fixture);
		data.i18n = {
			...data.i18n,
			closure: 'Cierre',
			tenders: 'Pagos',
			tax_rates: 'Impuestos',
			voided: 'Anulado',
		};
		const html = render(template, data);
		for (const text of ['Cierre', 'Pagos', 'Anulado', ...(slip ? [] : ['Impuestos'])])
			expect(html).toContain(text);
		for (const text of ['Closure 42', 'Tenders', 'Tax rates', 'Voided'])
			expect(html).not.toContain(text);
	});

	it('prints tender labels instead of slugs', () => {
		const data = structuredClone(fixture);
		data.closure.tenders[0].label = 'Cash drawer';
		data.closure.tenders[1].label = 'Credit card';
		const html = render(template, data);
		expect(html).toContain('Cash drawer');
		expect(html).toContain('Credit card');
	});

	it('keeps corrections out of the frozen closure printout', () => {
		const data = structuredClone(fixture);
		data.closure.corrections = [
			{
				id: 1,
				type: 'late_movement',
				actor: { id: 1, name: 'Alex' },
				approver: null,
				reason: 'Supplemental movement',
				created_at: '2026-09-12 08:00:00',
				figures: { cash_delta: '5.0000' },
			},
		];
		expect(render(template, data)).toBe(render(template, fixture));
	});

	it('renders an X-report copy and omits corrections', () => {
		const data = structuredClone(fixture);
		data.fiscal.is_x_report = true;
		data.fiscal.receipt_number = '';
		data.fiscal.is_reprint = true;
		data.fiscal.reprint_count = 2;
		data.order = { printed: { datetime: '2026-09-11 18:00' } };
		data.closure.corrections = [{ reason: 'DO NOT PRINT THIS' }];
		const html = render(template, data);
		expect(html).toContain('X-report');
		expect(html).toContain('COPY 2');
		expect(html).toContain('2026-09-11 18:00');
		expect(html).not.toContain('Closure ');
		expect(html).toContain('Interim report · not a closure · totals continue');
		expect(html).not.toContain('DO NOT PRINT THIS');
	});
});

it('keeps thermal variance cells separate from translated non-exact details at 42 columns', () => {
	const template = fs.readFileSync(path.join(gallery, 'thermal-closure-80mm.xml'), 'utf8');
	const data = structuredClone(fixture);
	data.closure.tenders.push({
		name: 'voucher',
		label: 'Voucher',
		expected_display: '$9.00',
		counted_display: '$10.00',
		variance_display: '$1.00',
		variance_label: 'Over',
		has_variance: true,
		variance_absolute_display: '$1.00',
	});
	data.closure.tenders[0].variance_label = 'Faltante';
	const root = document.createElement('div');
	root.innerHTML = renderThermalPreview(
		template.replace('paper-width="48"', 'paper-width="42"'),
		data
	);
	const rows = Array.from(root.querySelectorAll('div')).filter(
		(el) => el.style.display === 'flex'
	);
	const cash = rows.find((row) => row.children.length === 4 && row.firstElementChild?.textContent === 'Cash')!;
	expect(cash).toBeDefined();
	expect(cash.lastElementChild?.textContent).toBe('-£2.00');
	for (const cell of Array.from(cash.children)) {
		expect(cell.textContent!.length).toBeLessThanOrEqual(10);
	}
	expect(root.textContent).toContain('Cash Faltante £2.00');
	expect(root.textContent).toContain('Voucher Over $1.00');
	expect(root.textContent).not.toContain('Exact');
});

it('normalizes offline labels and amounts using the currency snapshot without inventing missing counts', () => {
	const data = sanitizeReceiptDataForRendering({
		order: { currency: 'USD' },
		i18n: { short: 'Faltante' },
		closure: {
			expected: { cash: '10.0000', credit_card: '2.0000' },
			counted: { cash: '9.0000' },
			variance: { cash: '-1.0000' },
			breakdowns: {
				currency: 'EUR',
				payment_methods: [{ method: 'cash', name: 'Cash drawer' }],
			},
		},
	});
	expect(data.closure).toMatchObject({
		tenders: [
			{
				label: 'Cash drawer',
				expected_display: '€10.00',
				counted_display: '€9.00',
				variance_display: '-€1.00',
				has_variance: true,
				variance_absolute_display: '€1.00',
				variance_label: 'Faltante',
			},
			{
				label: 'Credit Card',
				expected_display: '€2.00',
				counted_display: '',
				variance_display: '',
				has_variance: false,
				variance_label: '',
			},
		],
	});
});
