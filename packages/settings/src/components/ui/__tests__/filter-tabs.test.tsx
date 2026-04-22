import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import { FilterTabs } from '@wcpos/ui';

describe('FilterTabs', () => {
	const items = [
		{ key: 'all', label: 'All' },
		{ key: 'error', label: 'Errors' },
		{ key: 'warning', label: 'Warnings' },
		{ key: 'disabled', label: 'Disabled', disabled: true },
	] as const;

	it('renders tabs, marks active via aria-pressed, and fires onChange on click', () => {
		const onChange = vi.fn();

		render(<FilterTabs items={items} value="error" onChange={onChange} />);

		const activeTab = screen.getByRole('button', { name: 'Errors' });
		const inactiveTab = screen.getByRole('button', { name: 'Warnings' });

		expect(activeTab).toHaveAttribute('aria-pressed', 'true');
		expect(inactiveTab).toHaveAttribute('aria-pressed', 'false');

		fireEvent.click(inactiveTab);
		expect(onChange).toHaveBeenCalledWith('warning');
	});

	it('does not trigger onChange for disabled tabs', () => {
		const onChange = vi.fn();

		render(<FilterTabs items={items} value="error" onChange={onChange} />);

		const disabledTab = screen.getByRole('button', { name: 'Disabled' });
		expect(disabledTab).toBeDisabled();

		fireEvent.click(disabledTab);
		expect(onChange).not.toHaveBeenCalled();
	});

	it('renders a sliding indicator inside the tab container', () => {
		const { container } = render(
			<FilterTabs items={items} value="error" onChange={() => {}} />
		);

		const indicator = container.querySelector('[data-testid="filter-tabs-indicator"]');
		expect(indicator).not.toBeNull();
	});
});
