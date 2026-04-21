import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { GalleryGridSkeleton, GALLERY_GRID_CLASS } from '../components/skeleton';
import { GalleryGrid } from '../screens/gallery-grid';
import { TemplateCard } from '../components/template-card';
import type { GalleryTemplate } from '../types';

vi.mock('../components/filter-sidebar', () => ({
	DEFAULT_FILTERS: { search: '', categories: [], output: 'all' },
	FilterSidebar: () => <div data-testid="filter-sidebar" />,
}));

vi.mock('../components/active-templates-table', () => ({
	TemplatesTable: () => <div data-testid="templates-table" />,
}));

vi.mock('../components/preview-modal', () => ({
	PreviewModal: () => null,
}));

vi.mock('../hooks/use-gallery-templates', () => ({
	useGalleryTemplates: () => ({
		data: [galleryTemplate],
	}),
	useInstallGalleryTemplate: () => ({
		isPending: false,
		mutate: vi.fn(),
		variables: null,
	}),
}));

vi.mock('../hooks/use-templates', () => ({
	useTemplates: () => ({
		data: [],
	}),
	useToggleTemplate: () => ({
		isPending: false,
		mutate: vi.fn(),
		variables: null,
	}),
	useToggleVirtualTemplate: () => ({
		isPending: false,
		mutate: vi.fn(),
		variables: null,
	}),
	useReorderTemplates: () => ({
		mutate: vi.fn(),
	}),
	useDeleteTemplate: () => ({
		isPending: false,
		mutate: vi.fn(),
		variables: null,
	}),
}));

vi.mock('../translations', () => ({
	t: (key: string) => key,
}));

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

beforeEach(() => {
	(window as Window & { wcpos?: { templateGallery?: { adminUrl?: string } } }).wcpos = {
		templateGallery: {
			adminUrl: 'https://example.test/wp-admin',
		},
	};
});

afterEach(() => {
	delete (window as Window & { wcpos?: unknown }).wcpos;
});

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

	it('uses the shared wrapper and grid classes in the loading skeleton', () => {
		const markup = renderToStaticMarkup(<GalleryGridSkeleton />);

		expect(markup).toContain('wcpos:flex-1 wcpos:min-w-0');
		expect(markup).toContain(GALLERY_GRID_CLASS);
	});

	it('uses the shared wrapper and grid classes in the live gallery', () => {
		const markup = renderToStaticMarkup(<GalleryGrid />);

		expect(markup).toContain('wcpos:flex-1 wcpos:min-w-0');
		expect(markup).toContain(GALLERY_GRID_CLASS);
	});
});
