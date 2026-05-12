import { describe, expect, it } from 'vitest';

import { buildPreviewFrameHtml, normalizePreviewPaperWidth } from './preview-frame';

describe('normalizePreviewPaperWidth', () => {
	it.each([
		['58mm', '58mm'],
		['80mm', '80mm'],
		['a4', 'a4'],
		['A4', 'a4'],
		[null, 'a4'],
		[undefined, 'a4'],
		['letter', 'a4'],
	])('normalizes %s to %s', (input, expected) => {
		expect(normalizePreviewPaperWidth(input)).toBe(expected);
	});
});

describe('buildPreviewFrameHtml', () => {
	it('wraps thermal HTML in an 80mm physical paper preview frame', () => {
		const html = buildPreviewFrameHtml({ bodyHtml: '<div>Receipt</div>', paperWidth: '80mm' });

		expect(html).toContain('width:80mm');
		expect(html).toContain('background:#f5f5f5');
		expect(html).toContain('<div>Receipt</div>');
	});

	it('wraps 58mm previews with 58mm paper width', () => {
		const html = buildPreviewFrameHtml({ bodyHtml: '<div>Receipt</div>', paperWidth: '58mm' });

		expect(html).toContain('width:58mm');
	});

	it('uses A4 physical dimensions without shrinking to iframe width', () => {
		const html = buildPreviewFrameHtml({ bodyHtml: '<main>Invoice</main>', paperWidth: null });

		expect(html).toContain('width:210mm');
		expect(html).toContain('min-height:297mm');
		expect(html).not.toContain('max-width:100%');
		expect(html).not.toContain('max-width: 100%');
	});

	it('keeps preview chrome CSS scoped away from template content', () => {
		const html = buildPreviewFrameHtml({
			bodyHtml: '<section style="display:flex;justify-content:space-between"><span>A</span><span>B</span></section>',
			paperWidth: 'a4',
		});

		expect(html).toContain('overflow:auto');
		expect(html).not.toContain('*{box-sizing:border-box;}');
		expect(html).toContain('display:flex;justify-content:space-between');
	});
});
