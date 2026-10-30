import fs from 'node:fs';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

import { renderLogiclessPreview } from './logicless-renderer';
import { renderThermalPreview } from './thermal-renderer';

const gallery = path.resolve(__dirname, '../../../templates/gallery');
const fixture = JSON.parse(fs.readFileSync(path.join(gallery, 'preview-data/report-sales.json'), 'utf8'));

describe.each([
	['thermal-report-58mm.xml', renderThermalPreview],
	['report-letterhead.html', renderLogiclessPreview],
] as const)('%s', (file, render) => {
	const template = fs.existsSync(path.join(gallery, file)) ? fs.readFileSync(path.join(gallery, file), 'utf8') : '';

	it('renders grouped rows, subtotals, totals and identity without raw fields', () => {
		const html = render(template, fixture);
		for (const text of ['Sales report', 'By payment method', 'Coffee Monster', 'Front till · Session 42', 'Card payments', 'Cash payments', 'Visa', 'Mastercard', 'Morning cash', 'Afternoon cash', '£90.00', '£95.00', '£185.00', 'Total', '15 Sept 2026 17:00', 'WCPOS']) expect(html).toContain(text);
		expect(html).not.toMatch(/undefined|\{\{|\d+\.\d{4}|payment_method|generated_at/);
		if (file.endsWith('.xml')) {
			const root = document.createElement('div');
			root.innerHTML = html;
			const rows = Array.from(root.querySelectorAll('div')).filter(el => el.style.display === 'flex');
			expect(rows.length).toBe(28);
			for (const row of rows) expect(row.children.length).toBe(2);
			expect(rows[0].textContent).toBe('Payments3');
			expect(root.textContent!.match(/Visa/g)).toHaveLength(1);
			expect(root.textContent!.match(/Subtotal/g)).toHaveLength(2);
		} else {
			for (const text of ['123 Bean Street', '020 1234 5678', 'Generated', 'Alex']) expect(html).toContain(text);
		}
	});

	it('renders ungrouped rows and partial-report warnings', () => {
		const data = structuredClone(fixture);
		data.report.has_groups = false;
		data.report.rows = data.report.groups[0].rows;
		data.report.groups = [];
		data.report.is_partial = true;
		data.report.partial_reason = 'Offline register excluded';
		const html = render(template, data);
		for (const text of ['Visa', 'Mastercard', 'Offline register excluded', '£185.00']) expect(html).toContain(text);
		expect(html).not.toContain('Card payments');
	});

	it('honors the document guard and translated labels', () => {
		const data = structuredClone(fixture);
		data.i18n = { subtotal: 'Sous-total', total: 'Somme', generated: 'Généré' };
		const html = render(template, data);
		expect(html).toContain(file.endsWith('.xml') ? 'Sous-total' : 'Généré');
		data.fiscal.is_report_document = false;
		expect(render(template, data)).not.toContain('Sales report');
	});
});
