import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { NavItem } from '@wcpos/ui';

describe('NavItem', () => {
	it('marks the active item with aria-current and the semibold class', () => {
		render(<NavItem href="#/general" label="General" active />);
		const link = screen.getByRole('link', { name: 'General' });

		expect(link.getAttribute('aria-current')).toBe('page');
		expect(link.className).toContain('wcpos:font-semibold');
	});

	it('renders a number badge as a round neutral chip and hides zero', () => {
		const { rerender } = render(
			<NavItem href="#/logs" label="Logs" active={false} badge={3} testId="nav-logs" />
		);
		const chip = screen.getByText('3');

		expect(chip.className).toContain('wcpos:bg-gray-100');
		expect(chip.className).toContain('wcpos:rounded-full');

		rerender(<NavItem href="#/logs" label="Logs" active={false} badge={0} testId="nav-logs" />);

		expect(screen.getByTestId('nav-logs').querySelector('span')).toBeNull();
	});

	it('renders a text badge with its variant', () => {
		render(
			<NavItem
				href="#/sessions"
				label="Sessions"
				active={false}
				badge={{ label: '1 open', variant: 'success' }}
			/>
		);

		const chip = screen.getByText('1 open');
		expect(chip.className).toContain('wcpos:bg-green-50');
		expect(chip.className).toContain('wcpos:min-w-5');
	});

	it('renders error and warning counts as two chips', () => {
		render(<NavItem href="#/logs" label="Logs" active={false} badge={{ error: 2, warning: 1 }} />);

		expect(screen.getByText('2').className).toContain('wcpos:bg-red-100');
		expect(screen.getByText('1').className).toContain('wcpos:bg-amber-50');
	});
});
