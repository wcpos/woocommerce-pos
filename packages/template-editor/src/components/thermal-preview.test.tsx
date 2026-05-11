import { describe, expect, it } from 'vitest';

import { buildThermalPreviewSrcDoc } from './thermal-preview';

describe('buildThermalPreviewSrcDoc', () => {
	it('uses the shared preview frame with physical paper width', () => {
		const srcDoc = buildThermalPreviewSrcDoc({
			content: '<receipt paper-width="32"><text>Test receipt</text></receipt>',
			sampleData: {},
			paperWidth: '58mm',
		});

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:58mm');
		expect(srcDoc).toContain('Test receipt');
	});
});
