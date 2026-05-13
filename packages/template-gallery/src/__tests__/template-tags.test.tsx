import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { TemplateTags } from '../components/template-tags';
import type { GalleryTemplate } from '../types';

vi.mock('../translations', () => ({
	t: (key: string) => key,
}));

const baseRtl: GalleryTemplate = {
	key: 'standard-receipt-rtl',
	title: 'Standard Receipt (RTL)',
	description: '',
	type: 'receipt',
	category: 'receipt',
	engine: 'logicless',
	output_type: 'html',
	paper_width: null,
	direction: 'rtl',
	version: 1,
	is_premade: true,
	is_virtual: true,
	source: 'gallery',
	offline_capable: true,
};

const baseLtr: GalleryTemplate = {
	...baseRtl,
	key: 'standard-receipt',
	title: 'Standard Receipt',
	direction: 'ltr',
};

describe('TemplateTags RTL chip', () => {
	it('renders the RTL chip when direction is rtl', () => {
		const markup = renderToStaticMarkup(<TemplateTags template={baseRtl} />);

		expect(markup).toContain('tags.rtl');
		expect(markup).toContain('tags.rtl_tip');
	});

	it('does not render the RTL chip when direction is ltr', () => {
		const markup = renderToStaticMarkup(<TemplateTags template={baseLtr} />);

		expect(markup).not.toContain('tags.rtl_tip');
		// The translation key for the chip body matches the chip on the LTR template's
		// 'tags.rtl' label too — assert against the tooltip instead, which is unique.
	});
});
