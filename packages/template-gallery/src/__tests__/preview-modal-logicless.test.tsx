import { describe, expect, it } from 'vitest';

import { buildPreviewModalSrcDoc } from '../components/preview-modal';
import type { PreviewResponse } from '../types';

describe('PreviewModal logicless previews', () => {
	it('renders template_content with receipt_data instead of sanitized preview_html', () => {
		const preview: PreviewResponse = {
			engine: 'logicless',
			template_content: '<div style="display:flex;justify-content:space-between"><span>{{label}}</span><span>{{amount}}</span></div>',
			receipt_data: { label: 'Subtotal (sin impuestos)', amount: '15,00 $' },
			preview_html: '<div style="justify-content:space-between"><span>Subtotal (sin impuestos)</span><span>15,00 $</span></div>',
			order_id: 0,
			template_id: 'invoice',
		};

		const srcDoc = buildPreviewModalSrcDoc(preview);

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:210mm');
		expect(srcDoc).toContain('style="display:flex;justify-content:space-between"');
		expect(srcDoc).not.toContain('style="justify-content:space-between"');
		expect(srcDoc).toContain('Subtotal (sin impuestos)');
		expect(srcDoc).toContain('15,00 $');
	});

	it('wraps preview_html fallback when source template data is missing', () => {
		const preview: PreviewResponse = {
			engine: 'logicless',
			preview_html: '<main>Fallback preview</main>',
			order_id: 0,
			template_id: 'invoice',
		};

		const srcDoc = buildPreviewModalSrcDoc(preview);

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:210mm');
		expect(srcDoc).toContain('<main>Fallback preview</main>');
	});

	it('keeps full HTML preview_html fallback untouched', () => {
		const fullHtml = '<!DOCTYPE html><html><body><main>Full HTML</main></body></html>';
		const preview: PreviewResponse = {
			engine: 'logicless',
			preview_html: fullHtml,
			order_id: 0,
			template_id: 'invoice',
		};

		expect(buildPreviewModalSrcDoc(preview)).toBe(fullHtml);
	});
});
