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

	it('keeps the in-flow sidebar unchanged when overlay is not set', () => {
		const { container } = render(
			<NavSidebar isOpen brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		expect(container.querySelector('aside')?.className).toBe(
			'wcpos:w-56 wcpos:shrink-0 wcpos:border-r wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col wcpos:transition-[margin] wcpos:duration-300 wcpos:ease-in-out wcpos:lg:ml-0 wcpos:ml-0'
		);
		expect(container.querySelector('aside')?.className).not.toContain('wcpos:fixed');
		expect(container.querySelector('button')).toBeNull();
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

		const backdrop = container.querySelector('aside + button') as HTMLButtonElement;
		expect(backdrop.getAttribute('aria-label')).toBe('Close menu');
		expect(backdrop.className).toContain('wcpos:lg:hidden');
		expect(backdrop.className).toContain('wcpos:z-[9991]');
		fireEvent.click(backdrop);
		expect(onClose).toHaveBeenCalledTimes(1);

		rerender(
			<NavSidebar isOpen={false} overlay onClose={onClose} brand="WCPOS">
				<span>groups</span>
			</NavSidebar>
		);

		expect(container.querySelector('aside + button')).toBeNull();
		expect(container.querySelector('aside')?.className).toContain(
			'wcpos:invisible wcpos:lg:visible'
		);
	});

	it('lets the title bar wrap and labels the hamburger with menuLabel', () => {
		render(
			<ShellLayout
				sidebar={<aside />}
				title={<h1>Title</h1>}
				mobileNavOpen={false}
				onToggleMobileNav={() => {}}
				menuLabel="Menü öffnen"
			>
				content
			</ShellLayout>
		);

		const titleBar = screen.getByText('Title').parentElement as HTMLElement;
		expect(titleBar.className).toContain('wcpos:min-h-12');
		expect(titleBar.className).toContain('wcpos:flex-wrap');
		expect(titleBar.className.split(' ')).not.toContain('wcpos:h-12');
		expect(screen.getByRole('button', { name: 'Menü öffnen' })).not.toBeNull();
	});
});
