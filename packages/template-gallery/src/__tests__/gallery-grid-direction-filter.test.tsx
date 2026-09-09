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

// An installed template is a post, not a gallery entry: it must carry no `key`.
const { key: _installedKey, ...installedDisplay } = {
	...ltrTemplate,
	id: 'installed-display',
	type: 'display',
	is_disabled: false,
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
		data:
			useSearch({ from: '/' }).type === 'display'
				? [
						{
							...ltrTemplate,
							key: 'phone',
							title: 'Phone Display',
							type: 'display',
							category: 'small-screen',
						},
						{
							...ltrTemplate,
							key: 'responsive',
							title: 'Responsive Display',
							type: 'display',
							category: 'responsive',
						},
						{
							...ltrTemplate,
							key: 'large',
							title: 'Large Display',
							type: 'display',
							category: 'large-screen',
						},
						{ ...legacyTemplate, type: 'display', category: 'responsive' },
					]
				: [ltrTemplate, rtlTemplate, legacyTemplate],
	}),
	useInstallGalleryTemplate: () => ({
		isPending: false,
		mutate: vi.fn(),
		variables: null,
	}),
}));

vi.mock('../hooks/use-templates', () => ({
	useTemplates: () => ({
		data: useSearch({ from: '/' }).type === 'display' ? [installedDisplay] : [],
	}),
	useSetActiveTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
	useToggleTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
	useToggleVirtualTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
	useReorderTemplates: () => ({ mutate: vi.fn() }),
	useDeleteTemplate: () => ({ isPending: false, mutate: vi.fn(), variables: null }),
}));

vi.mock('../hooks/use-preview', () => ({ usePreview: vi.fn(() => ({})) }));

vi.mock('../translations', () => ({
	t: (key: string) => key,
}));

const mountedRoots: Root[] = [];

beforeEach(() => {
	vi.stubGlobal(
		'ResizeObserver',
		class {
			observe() {}
			disconnect() {}
		}
	);
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
	vi.unstubAllGlobals();
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
	it('filters by screen-fit category in gallery order and supports clearing', () => {
		vi.mocked(useSearch).mockReturnValue({ type: 'display' });
		const container = mountGrid();
		expect(container.textContent).toContain('Responsive Display');
		expect(container.textContent).toContain('Large Display');
		const categories = Array.from(
			container.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')
		);
		expect(categories.map((input) => input.parentElement?.textContent)).toEqual([
			'category.small-screen',
			'category.responsive',
			'category.large-screen',
		]);
		const phone = categories[0];
		expect(phone).not.toBeNull();
		act(() => phone!.click());
		expect(container.textContent).toContain('Phone Display');
		expect(container.textContent).not.toContain('Legacy Receipt');
		expect(container.textContent).not.toContain('Responsive Display');
		expect(container.textContent).not.toContain('Large Display');
		const responsive = categories[1];
		act(() => {
			phone!.click();
			responsive!.click();
		});
		expect(container.textContent).toContain('Legacy Receipt');
		expect(container.textContent).toContain('Responsive Display');
		expect(container.textContent).not.toContain('Phone Display');
		const clear = Array.from(container.querySelectorAll('button')).find(
			(button) => button.textContent === 'filter.clear_all'
		);
		expect(clear).toBeDefined();
		act(() => clear!.click());
		expect(container.textContent).toContain('Responsive Display');
		expect(container.textContent).toContain('Large Display');
	});

	it('shows the Pro requirement, display creation link and previews without output filters', () => {
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
		expect(container.querySelector('button[aria-label="common.preview"]')).not.toBeNull();
		expect(container.textContent).toContain('common.use_template');
	});

	it.each(['thumbnail', 'card', 'table'])('opens display modal from the %s', (source) => {
		vi.mocked(useSearch).mockReturnValue({ type: 'display' });
		Object.assign((window as any).wcpos.templateGallery, {
			isProActive: true,
			displayPreviewUrl: 'https://example.test/wcpos-display/',
		});
		const container = mountGrid();
		const selector = source === 'table' ? 'tbody button' : 'section:nth-child(2) button';
		const previews = Array.from(container.querySelectorAll<HTMLButtonElement>(selector)).filter(
			(button) =>
				button.textContent === 'common.preview' ||
				button.getAttribute('aria-label') === 'common.preview'
		);
		act(() => previews[source === 'card' ? 1 : 0]!.click());
		expect(container.querySelector('[role="dialog"] iframe')?.getAttribute('src')).toBe(
			`https://example.test/wcpos-display/?preview=cart&${source === 'table' ? 'template=installed-display' : 'gallery=phone'}`
		);
	});

	it('does not apply a receipt direction filter after switching to display', () => {
		const container = mountGrid();
		clickDirection(container, 'rtl');
		vi.mocked(useSearch).mockReturnValue({ type: 'display' });
		act(() => mountedRoots[0].render(<GalleryGrid />));
		expect(container.textContent).toContain('Legacy Receipt');
	});
});
