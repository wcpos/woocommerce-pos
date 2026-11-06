import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import { NavSidebar, ShellLayout } from '@wcpos/ui';

describe('ShellLayout', () => {
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

	it('renders the hamburger with aria-expanded bound to mobileNavOpen', () => {
		const onToggle = vi.fn();
		const { container, rerender } = render(
			<ShellLayout sidebar={<aside />} title="General" mobileNavOpen onToggleMobileNav={onToggle}>
				content
			</ShellLayout>
		);

		const hamburger = container.querySelector(
			'button[aria-label="Open main menu"][aria-expanded="true"]'
		);
		expect(hamburger).not.toBeNull();

		fireEvent.click(hamburger as HTMLButtonElement);
		expect(onToggle).toHaveBeenCalledTimes(1);

		rerender(
			<ShellLayout
				sidebar={<aside />}
				title="General"
				mobileNavOpen={false}
				onToggleMobileNav={onToggle}
			>
				content
			</ShellLayout>
		);

		expect(
			container.querySelector('button[aria-label="Open main menu"][aria-expanded="false"]')
		).not.toBeNull();
	});

	it('renders title, titleEnd, children and footer in order', () => {
		const { container } = render(
			<ShellLayout
				sidebar={<aside />}
				title={<h1>Title</h1>}
				titleEnd={<span>End</span>}
				mobileNavOpen={false}
				onToggleMobileNav={() => {}}
				footer={<footer>Footer</footer>}
			>
				<p>Body</p>
			</ShellLayout>
		);

		const text = container.textContent ?? '';
		const positions = ['Title', 'End', 'Body', 'Footer'].map((s) => text.indexOf(s));

		expect(positions.every((p) => p >= 0)).toBe(true);
		expect([...positions].sort((a, b) => a - b)).toEqual(positions);
		expect(screen.getByText('Body').closest('main')?.className).toBe(
			'wcpos:flex-1 wcpos:px-6 wcpos:py-6'
		);
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
});
