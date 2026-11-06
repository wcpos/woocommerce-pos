import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import { NavSidebar } from '@wcpos/ui';

describe('NavSidebar', () => {
	it('hides the sidebar off-canvas when closed and shows it when open', () => {
		const { container, rerender } = render(
			<NavSidebar isOpen={false} brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		expect(container.querySelector('aside')?.className).toContain('wcpos:-ml-56');

		rerender(
			<NavSidebar isOpen brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		const className = container.querySelector('aside')?.className ?? '';
		expect(className).toContain('wcpos:ml-0');
		expect(className).not.toContain('wcpos:-ml-56');
	});

	it('renders the close control only when onClose is given', () => {
		const { rerender } = render(
			<NavSidebar isOpen brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		expect(screen.queryByRole('button', { name: 'Close menu' })).toBeNull();

		const onClose = vi.fn();
		rerender(
			<NavSidebar isOpen brand="WCPOS" onClose={onClose}>
				<span>groups</span>
			</NavSidebar>
		);

		const close = screen.getByRole('button', { name: 'Close menu' });
		expect(close.className).toContain('wcpos:lg:hidden');

		fireEvent.click(close);
		expect(onClose).toHaveBeenCalledTimes(1);
	});

	it('keeps the in-flow sidebar unchanged when overlay is not set', () => {
		const { container } = render(
			<NavSidebar isOpen brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		expect(container.querySelector('aside')?.className).toBe(
			'wcpos:w-56 wcpos:shrink-0 wcpos:border-r wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col wcpos:transition-[margin] wcpos:duration-300 wcpos:ease-in-out wcpos:lg:ml-0 wcpos:ml-0'
		);
		expect(screen.queryByTestId('nav-backdrop')).toBeNull();
	});

	it('renders an overlay drawer with a backdrop that calls onClose', () => {
		const onClose = vi.fn();
		const { container, rerender } = render(
			<NavSidebar isOpen overlay onClose={onClose} brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		const aside = container.querySelector('aside') as HTMLElement;
		expect(aside.className).toContain('wcpos:fixed');
		expect(aside.className).toContain('wcpos:lg:static');
		expect(aside.className).toContain('wcpos:z-[9992]');
		expect(aside.className).toContain('wcpos:lg:z-auto');
		expect(aside.className).not.toContain('wcpos:invisible');

		const backdrop = screen.getByTestId('nav-backdrop');
		expect(backdrop.tagName).toBe('DIV');
		expect(backdrop.getAttribute('aria-hidden')).toBe('true');
		expect(backdrop.className).toContain('wcpos:z-[9991]');
		expect(backdrop.className).toContain('wcpos:lg:hidden');
		// The × is the only accessible close control.
		expect(screen.getAllByRole('button', { name: 'Close menu' })).toHaveLength(1);
		fireEvent.click(backdrop);
		expect(onClose).toHaveBeenCalledTimes(1);

		rerender(
			<NavSidebar isOpen={false} overlay onClose={onClose} brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		expect(screen.queryByTestId('nav-backdrop')).toBeNull();
		expect(container.querySelector('aside')?.className).toContain(
			'wcpos:invisible wcpos:lg:visible'
		);
	});
});
