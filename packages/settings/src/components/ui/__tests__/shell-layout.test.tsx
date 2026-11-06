import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import { ShellLayout } from '@wcpos/ui';

describe('ShellLayout', () => {
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

	it('renders title, children and footer in order', () => {
		const { container } = render(
			<ShellLayout
				sidebar={<aside />}
				title={<h1>Title</h1>}
				mobileNavOpen={false}
				onToggleMobileNav={() => {}}
				footer={<footer>Footer</footer>}
			>
				<p>Body</p>
			</ShellLayout>
		);

		const text = container.textContent ?? '';
		const positions = ['Title', 'Body', 'Footer'].map((s) => text.indexOf(s));

		expect(positions.every((p) => p >= 0)).toBe(true);
		expect([...positions].sort((a, b) => a - b)).toEqual(positions);
		expect(screen.getByText('Body').closest('main')?.className).toBe(
			'wcpos:flex-1 wcpos:px-6 wcpos:py-6'
		);
	});

	it('renders the title bare after the hamburger in a bar that can wrap', () => {
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

		const heading = screen.getByRole('heading', { name: 'Title' });
		const hamburger = screen.getByRole('button', { name: 'Menü öffnen' });
		expect(heading.previousElementSibling).toBe(hamburger);
		expect(heading.nextElementSibling).toBeNull();

		const titleBar = heading.parentElement as HTMLElement;
		expect(titleBar.className).toContain('wcpos:min-h-12');
		expect(titleBar.className).toContain('wcpos:flex-wrap');
		expect(titleBar.className.split(' ')).not.toContain('wcpos:h-12');
	});
});
