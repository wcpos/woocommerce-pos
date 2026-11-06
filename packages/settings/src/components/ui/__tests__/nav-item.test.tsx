import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import { Chip, NavItem } from '@wcpos/ui';

describe('NavItem', () => {
	it('marks the active item with aria-current and the semibold class', () => {
		render(<NavItem href="#/general" label="General" active />);
		const link = screen.getByRole('link', { name: 'General' });

		expect(link.getAttribute('aria-current')).toBe('page');
		expect(link.className).toContain('wcpos:font-semibold');
	});

	it('passes anchor attributes through and appends className', () => {
		const onClick = vi.fn();
		render(
			<NavItem
				href="#/logs"
				label="Logs"
				active={false}
				className="active"
				data-testid="nav-logs"
				onClick={onClick}
			/>
		);
		const link = screen.getByTestId('nav-logs');

		expect(link.getAttribute('href')).toBe('#/logs');
		expect(link.getAttribute('aria-current')).toBeNull();
		expect(link.className).toContain('wcpos:text-gray-600');
		expect(link.className.endsWith(' active')).toBe(true);
		fireEvent.click(link);
		expect(onClick).toHaveBeenCalledTimes(1);
	});

	it('renders the badge slot after the label', () => {
		render(
			<NavItem
				href="#/sessions"
				label="Sessions"
				active={false}
				badge={
					<Chip variant="success" size="xs">
						1 open
					</Chip>
				}
			/>
		);

		const chip = screen.getByText('1 open');
		expect(chip.parentElement).toBe(screen.getByRole('link', { name: /^Sessions/ }));
		expect(chip.previousSibling?.textContent).toBe('Sessions');
	});
});
