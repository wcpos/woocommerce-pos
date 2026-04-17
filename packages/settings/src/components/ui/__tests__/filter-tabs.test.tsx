import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import { FilterTabs } from '@wcpos/ui';

describe('FilterTabs', () => {
	const items = [
		{ key: 'all', label: 'All' },
		{ key: 'error', label: 'Errors' },
		{ key: 'warning', label: 'Warnings' },
	] as const;

	it('renders active and inactive tabs with button-like cursor behavior', () => {
		const onChange = vi.fn();

		render(<FilterTabs items={items} value="error" onChange={onChange} />);

		const activeTab = screen.getByRole('button', { name: 'Errors' });
		const inactiveTab = screen.getByRole('button', { name: 'Warnings' });

		expect(activeTab.className).toContain('wcpos:bg-wp-admin-theme-color');
		expect(activeTab.className).toContain('wcpos:cursor-pointer');
		expect(inactiveTab.className).toContain('hover:wcpos:bg-gray-200');
		expect(inactiveTab.className).toContain('wcpos:cursor-pointer');

		fireEvent.click(inactiveTab);
		expect(onChange).toHaveBeenCalledWith('warning');
	});
});
