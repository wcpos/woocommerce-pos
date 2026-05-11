import { describe, expect, it } from 'vitest';

import { buildPreviewModalSrcDoc } from '../components/preview-modal';
import type { PreviewResponse } from '../types';

describe('PreviewModal thermal previews', () => {
	it('renders thermal template_content with receipt_data inside the shared 58mm frame', () => {
		const preview: PreviewResponse = {
			engine: 'thermal',
			template_content: '<receipt paper-width="32"><text>Thermal XML {{order.number}}</text></receipt>',
			receipt_data: { order: { number: '1234' } },
			paper_width: '58mm',
			order_id: 0,
			template_id: 'thermal-simple-58mm',
		};

		const srcDoc = buildPreviewModalSrcDoc(preview);

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:58mm');
		expect(srcDoc).toContain('Thermal XML 1234');
		expect(srcDoc).not.toContain('<receipt');
	});
});
