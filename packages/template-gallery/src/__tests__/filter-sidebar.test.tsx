import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_FILTERS, FilterSidebar } from '../components/filter-sidebar';
import type { FilterState } from '../components/filter-sidebar';

vi.mock('../translations', () => ({
	t: (key: string) => key,
}));

const mountedRoots: Root[] = [];

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	mountedRoots.length = 0;
	document.body.innerHTML = '';
});

function mount(filters: FilterState, onChange: (next: FilterState) => void): HTMLElement {
	const container = document.createElement('div');
	document.body.appendChild(container);
	const root = createRoot(container);
	mountedRoots.push(root);
	act(() => {
		root.render(
			<FilterSidebar
				filters={filters}
				onChange={onChange}
				availableCategories={['receipt']}
				collapsed={false}
				onToggleCollapse={() => {}}
			/>,
		);
	});
	return container;
}

describe('FilterSidebar direction filter', () => {
	it('defaults to direction=all in DEFAULT_FILTERS', () => {
		expect(DEFAULT_FILTERS.direction).toBe('all');
	});

	it('renders a Direction radio group with three options', () => {
		const markup = renderToStaticMarkup(
			<FilterSidebar
				filters={{ ...DEFAULT_FILTERS }}
				onChange={() => {}}
				availableCategories={['receipt']}
				collapsed={false}
				onToggleCollapse={() => {}}
			/>,
		);

		expect(markup).toContain('filter.direction');
		expect(markup).toContain('name="filter-direction"');
		expect(markup).toContain('value="ltr"');
		expect(markup).toContain('value="rtl"');
	});

	it('emits direction=rtl when the RTL radio is clicked', () => {
		const onChange = vi.fn();
		const container = mount({ ...DEFAULT_FILTERS }, onChange);

		const rtlRadio = container.querySelector(
			'input[name="filter-direction"][value="rtl"]',
		) as HTMLInputElement | null;
		expect(rtlRadio).not.toBeNull();

		act(() => {
			rtlRadio!.click();
		});

		expect(onChange).toHaveBeenCalledWith({ ...DEFAULT_FILTERS, direction: 'rtl' });
	});
});
