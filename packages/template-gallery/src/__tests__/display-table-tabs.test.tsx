import { act } from 'react';

import { createRoot, type Root } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { TemplatesTable } from '../components/active-templates-table';
import { TypeTabs } from '../components/type-tabs';

import type { Template } from '../types';

const { navigate } = vi.hoisted(() => ({ navigate: vi.fn() }));
vi.mock('@tanstack/react-router', () => ({ useNavigate: () => navigate }));
vi.mock('../translations', () => ({ t: (key: string) => key }));

const template: Template = {
	id: 123,
	title: 'Display',
	description: '',
	type: 'display',
	category: 'display',
	language: '',
	engine: 'logicless',
	output_type: 'html',
	paper_width: null,
	tax_display: '',
	is_virtual: false,
	is_premade: false,
	status: 'publish',
	is_active: true,
	offline_capable: true,
	gallery_key: null,
	gallery_version: 1,
	source: 'custom',
	menu_order: 0,
	date_created: '',
	date_modified: '',
};
const props = {
	templates: [
		template,
		{ ...template, id: 124, is_active: false },
		{ ...template, id: 125, is_active: false, status: 'draft' as const },
	],
	type: 'display' as const,
	onPreview: vi.fn(),
	onToggle: vi.fn(),
	onDelete: vi.fn(),
	onReorder: vi.fn(),
	onSetActive: vi.fn(),
	togglingId: null,
	deletingId: null,
};
let root: Root | undefined;
afterEach(() => {
	act(() => root?.unmount());
	root = undefined;
	document.body.innerHTML = '';
	delete (window as any).wcpos;
	vi.unstubAllGlobals();
	vi.clearAllMocks();
});

function mount(element: React.ReactNode) {
	const container = document.createElement('div');
	document.body.append(container);
	root = createRoot(container);
	act(() => root!.render(element));
	return container;
}

describe('display tabs and table', () => {
	it('enables and highlights Display, navigates by search, and leaves Report/Email disabled', () => {
		const container = mount(<TypeTabs activeType="display" />);
		const buttons = Array.from(container.querySelectorAll('button'));
		const display = buttons.find((button) => button.textContent === 'tabs.display');
		expect(display).toBeDefined();
		expect(display?.disabled).toBe(false);
		expect(display?.className).toContain('wcpos:border-wp-admin-theme-color');
		act(() => display!.click());
		expect(navigate).toHaveBeenCalledWith({ to: '/', search: { type: 'display' } });
		expect(buttons.filter((button) => button.disabled).map((button) => button.textContent)).toEqual(
			['tabs.reportstabs.soon', 'tabs.emailtabs.soon']
		);
	});

	it('renders Live/Enabled columns, selected and disabled radios, and calls set-active', () => {
		vi.stubGlobal(
			'ResizeObserver',
			class {
				observe() {}
				disconnect() {}
			}
		);
		const container = mount(<TemplatesTable {...props} />);
		expect(container.querySelector('thead')?.textContent).toBe(
			'common.titlecommon.categorytable.header_livetable.header_enabledtable.header_actions'
		);
		const radios = container.querySelectorAll<HTMLInputElement>('input[name="wcpos-live-display"]');
		expect(radios).toHaveLength(3);
		expect(radios[0].checked).toBe(true);
		expect(radios[1].disabled).toBe(false);
		expect(radios[2].disabled).toBe(true);
		expect(radios[1].getAttribute('aria-label')).toBe('table.set_live');
		act(() => radios[1].click());
		expect(props.onSetActive).toHaveBeenCalledWith(124);
		act(() => root!.render(<TemplatesTable {...props} settingActiveId={124} />));
		expect(
			Array.from(container.querySelectorAll<HTMLInputElement>('input[type="radio"]')).every(
				(radio) => radio.disabled
			)
		).toBe(true);
	});

	it('looks up installed template categories through translations', () => {
		const markup = renderToStaticMarkup(
			<TemplatesTable {...props} templates={[{ ...template, category: 'seasonal' }]} />
		);

		expect(markup).toContain('category.seasonal');
	});

	it.each([true, false])('opens display previews with Pro active %s', (isProActive) => {
		(window as any).wcpos = { templateGallery: { isProActive } };
		vi.stubGlobal(
			'ResizeObserver',
			class {
				observe() {}
				disconnect() {}
			}
		);
		const container = mount(<TemplatesTable {...props} />);
		const preview = Array.from(container.querySelectorAll('button')).find(
			(button) => button.textContent === 'common.preview'
		);
		expect(preview).toBeDefined();
		act(() => preview!.click());
		expect(props.onPreview).toHaveBeenCalledWith(123);
		expect(container.querySelector('a[target="_blank"]')).toBeNull();
	});
});
