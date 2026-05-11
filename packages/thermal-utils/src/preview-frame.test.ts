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

	it('uses A4 sizing for html/default previews', () => {
		const html = buildPreviewFrameHtml({ bodyHtml: '<main>Invoice</main>', paperWidth: null });

		expect(html).toContain('width:210mm');
		expect(html).toContain('<main>Invoice</main>');
	});
});
