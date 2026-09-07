import { act } from 'react';

import { useSearch } from '@tanstack/react-router';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { GalleryGrid } from '../screens/gallery-grid';

import type { GalleryTemplate } from '../types';

const ltrTemplate: GalleryTemplate = {
	key: 'standard-receipt',
	title: 'Standard Receipt',
	description: 'LTR template',
	type: 'receipt',
	category: 'receipt',
	engine: 'logicless',
	output_type: 'html',
	paper_width: null,
	direction: 'ltr',
	version: 1,
	is_premade: true,
	is_virtual: true,
	source: 'gallery',
	offline_capable: true,
};

const rtlTemplate: GalleryTemplate = {
	...ltrTemplate,
	key: 'standard-receipt-rtl',
	title: 'Standard Receipt (RTL)',
	description: 'RTL template',
	direction: 'rtl',
};

const { direction: _direction, ...legacyTemplate } = {
	...ltrTemplate,
	key: 'legacy-receipt',
	title: 'Legacy Receipt',
	description: 'Payload without direction',
};

vi.mock('@tanstack/react-router', () => ({ useSearch: vi.fn(() => ({ type: 'receipt' })) }));

vi.mock('../hooks/use-gallery-templates', () => ({
	useGalleryTemplates: () => ({
		data: [ltrTemplate, rtlTemplate, legacyTemplate],
	}),
	useInstallGalleryTemplate: () => ({
		isPending: false,
		mutate: vi.fn(),
		variables: null,
	}),
}));

vi.mock('../hooks/use-templates', () => ({
	useTemplates: () => ({ data: [] }),
	useSetActiveTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
	useToggleTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
	useToggleVirtualTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
	useReorderTemplates: () => ({ mutate: vi.fn() }),
	useDeleteTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
}));

vi.mock('../components/active-templates-table', () => ({
	TemplatesTable: () => <div data-testid="templates-table" />,
}));

vi.mock('../components/preview-modal', () => ({
	PreviewModal: () => null,
}));

vi.mock('../translations', () => ({
	t: (key: string) => key,
}));

const mountedRoots: Root[] = [];

beforeEach(() => {
	vi.mocked(useSearch).mockReturnValue({ type: 'receipt' });
	(
		window as Window & {
			wcpos?: { templateGallery?: { adminUrl?: string; previewBaseUrl?: string } };
		}
	).wcpos = {
		templateGallery: {
			adminUrl: 'https://example.test/wp-admin',
			previewBaseUrl:
				'https://example.test/wp-content/plugins/woocommerce-pos/assets/img/template-gallery/previews',
		},
	};
});

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	mountedRoots.length = 0;
	document.body.innerHTML = '';
	delete (window as Window & { wcpos?: unknown }).wcpos;
});

function mountGrid(): HTMLElement {
	const container = document.createElement('div');
	document.body.appendChild(container);
	const root = createRoot(container);
	mountedRoots.push(root);
	act(() => {
		root.render(<GalleryGrid />);
	});
	return container;
}

function clickDirection(container: HTMLElement, value: 'all' | 'ltr' | 'rtl'): void {
	const radio = container.querySelector(
		`input[name="filter-direction"][value="${value}"]`
	) as HTMLInputElement | null;
	expect(radio).not.toBeNull();
	act(() => {
		radio!.click();
	});
}

describe('GalleryGrid direction filter', () => {
	it('shows both templates by default and hides LTR when filter=rtl', () => {
		const container = mountGrid();
		const text = () => container.textContent ?? '';

		expect(text()).toContain('Standard Receipt');
		expect(text()).toContain('Standard Receipt (RTL)');
		expect(text()).toContain('Legacy Receipt');

		clickDirection(container, 'rtl');

		expect(text()).not.toMatch(/Standard Receipt(?!\s*\(RTL\))/);
		expect(text()).toContain('Standard Receipt (RTL)');
		expect(text()).not.toContain('Legacy Receipt');
	});

	it('hides RTL templates and keeps missing-direction templates when filter=ltr', () => {
		const container = mountGrid();

		clickDirection(container, 'ltr');

		const text = container.textContent ?? '';
		expect(text).toContain('Standard Receipt');
		expect(text).toContain('Legacy Receipt');
		expect(text).not.toContain('Standard Receipt (RTL)');
	});
});

describe('GalleryGrid display templates', () => {
	it('shows the Pro requirement, display creation link and no output filters or previews', () => {
		vi.mocked(useSearch).mockReturnValue({ type: 'display' });
		Object.assign((window as any).wcpos.templateGallery, { isProActive: false });
		const container = mountGrid();

		expect(container.textContent).toContain('gallery.display_needs_pro');
		expect(container.querySelector('a.page-title-action')?.getAttribute('href')).toBe(
			'https://example.test/wp-admin/post-new.php?post_type=wcpos_template&wcpos_type=display'
		);
		expect(
			container.querySelector('a[href="https://docs.wcpos.com/customer-display"]')
		).not.toBeNull();
		expect(container.querySelector('input[name="filter-format"]')).toBeNull();
		expect(container.querySelector('input[name="filter-direction"]')).toBeNull();
		expect(container.querySelector('button[aria-label="common.preview"]')).toBeNull();
		expect(container.textContent).toContain('common.use_template');
	});

	it('does not apply a receipt direction filter after switching to display', () => {
		const container = mountGrid();
		clickDirection(container, 'rtl');
		vi.mocked(useSearch).mockReturnValue({ type: 'display' });
		act(() => mountedRoots[0].render(<GalleryGrid />));
		expect(container.textContent).toContain('Legacy Receipt');
	});
});
