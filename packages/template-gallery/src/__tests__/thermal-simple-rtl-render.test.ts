import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

import { renderThermalPreview } from '@wcpos/thermal-utils';

const galleryDir = path.resolve(__dirname, '../../../../templates/gallery');

const arabicData = {
	store: {
		name: 'متجر الجوهرة',
		address_lines: ['شارع الملك فهد ١٢٣', 'الرياض ١١٤٢٣'],
		phone: '+966 11 234 5678',
		email: 'hello@aljawhara.sa',
		logo: '',
		tax_ids: [{ type: 'sa_vat', value: '300000000000003', label: 'الرقم الضريبي' }],
		personal_notes: '',
		footer_imprint: 'متجر الجوهرة',
	},
	cashier: { name: 'محمد العتيبي' },
	customer: { name: 'فاطمة الزهراء' },
	order: {
		number: '1827',
		created: { datetime: '٢٠٢٦/٠٥/١٣ ١٤:٣٢' },
		customer_note: '',
	},
	tax: { display_excl: true, display_incl: false },
	lines: [
		{
			name: 'قميص قطن',
			sku: 'TSH-12',
			qty: 2,
			unit_price_display: '٧٥٫٠٠ ر.س',
			unit_price_incl_display: '٧٥٫٠٠ ر.س',
			line_total_display: '١٥٠٫٠٠ ر.س',
			line_total_incl_display: '١٥٠٫٠٠ ر.س',
		},
		{
			name: 'كوب قهوة',
			sku: 'MUG-01',
			qty: 1,
			unit_price_display: '٣٠٫٠٠ ر.س',
			unit_price_incl_display: '٣٠٫٠٠ ر.س',
			line_total_display: '٣٠٫٠٠ ر.س',
			line_total_incl_display: '٣٠٫٠٠ ر.س',
		},
	],
	fees: [],
	shipping: [],
	discounts: [],
	tax_summary: [{ label: 'ضريبة القيمة المضافة', rate: 15, tax_amount_display: '٢٧٫٠٠ ر.س' }],
	totals: {
		subtotal_display: '١٨٠٫٠٠ ر.س',
		subtotal_incl_display: '١٨٠٫٠٠ ر.س',
		total_incl_display: '٢٠٧٫٠٠ ر.س',
	},
	payments: [
		{
			method_title: 'بطاقة مدى',
			amount_display: '٢٠٧٫٠٠ ر.س',
		},
	],
	i18n: {
		order: 'الطلب',
		date: 'التاريخ',
		cashier: 'الكاشير',
		customer: 'العميل',
		subtotal: 'المجموع الفرعي',
		total: 'الإجمالي',
		included_tax: 'شامل الضريبة',
		discount: 'خصم',
		tendered: 'المدفوع',
		change: 'الباقي',
		paid: 'تم الدفع',
		customer_note: 'ملاحظة العميل',
		thank_you_purchase: 'شكراً لتسوقكم!',
	},
};

describe('thermal-simple-80mm-rtl renders with Arabic sample data', () => {
	const xml = fs.readFileSync(path.join(galleryDir, 'thermal-simple-80mm-rtl.xml'), 'utf8');

	it('renders Arabic store name, items, totals and payments', () => {
		const html = renderThermalPreview(xml, arabicData);

		expect(html).toContain('متجر الجوهرة');
		expect(html).toContain('شارع الملك فهد');
		expect(html).toContain('قميص قطن');
		expect(html).toContain('كوب قهوة');
		expect(html).toContain('١٥٠٫٠٠ ر.س');
		expect(html).toContain('٢٠٧٫٠٠ ر.س');
		expect(html).toContain('المجموع الفرعي');
		expect(html).toContain('الإجمالي');
		expect(html).toContain('بطاقة مدى');
		expect(html).toContain('1827');
		// Latin prefix on the order number renders inline alongside Arabic digits.
		expect(html).toContain('#1827');
	});

	it('places label column on the right and amount column on the left for the totals row', () => {
		const html = renderThermalPreview(xml, arabicData);
		const wrapper = document.createElement('div');
		wrapper.innerHTML = html;

		const totalLine = Array.from(wrapper.querySelectorAll('div[style*="display: flex"]'))
			.map((element) => element.textContent?.trim() ?? '')
			.find((text) => text.includes('الإجمالي') && text.includes('٢٠٧٫٠٠ ر.س'));
		expect(totalLine).toBeTruthy();
		const labelIndex = totalLine!.indexOf('الإجمالي');
		const amountIndex = totalLine!.indexOf('٢٠٧٫٠٠ ر.س');
		expect(amountIndex).toBeLessThan(labelIndex);
	});
});
