import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { TemplateCard } from '../components/template-card';
import type { GalleryTemplate } from '../types';

const galleryTemplate: GalleryTemplate = {
	key: 'branded-receipt',
	title: 'Branded Receipt',
	description:
		'Print-friendly A4 receipt with store logo, tabular line items, totals, and footer details.',
	type: 'receipt',
	category: 'receipt',
	engine: 'logicless',
	output_type: 'html',
	paper_width: null,
	version: 1,
	is_premade: true,
	is_virtual: true,
	source: 'gallery',
	offline_capable: true,
};

describe('TemplateCard gallery layout', () => {
	it('renders the full description and keeps the primary CTA on one line', () => {
		const markup = renderToStaticMarkup(
			<TemplateCard
				template={galleryTemplate}
				isGallery
				onPreview={() => {}}
				onCustomize={() => {}}
			/>,
		);

		expect(markup).toContain(galleryTemplate.description);
		expect(markup).not.toContain('wcpos:line-clamp-2');
		expect(markup).toContain('wcpos:whitespace-nowrap');
	});
});
