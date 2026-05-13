import { describe, expect, it } from 'vitest';

import { buildLivePreviewSrcDoc, getPreviewBodyClassName, getPreviewIframeStyle } from './live-preview';

describe('LivePreview helpers', () => {
	it('wraps rendered logicless HTML in the shared A4 preview frame', () => {
		const srcDoc = buildLivePreviewSrcDoc('<div style="display:flex"><span>A</span><span>B</span></div>');

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:210mm');
		expect(srcDoc).toContain('display:flex');
	});

	it('lets the iframe fill the preview panel with a taller default height', () => {
		const style = getPreviewIframeStyle();

		expect(style.width).toBe('100%');
		expect(style.maxWidth).toBeUndefined();
		expect(style.minHeight).toBe(560);
	});

	it('uses a flush preview body without p-4 padding', () => {
		expect(getPreviewBodyClassName()).not.toContain('p-4');
		expect(getPreviewBodyClassName()).toContain('p-0');
	});
});
