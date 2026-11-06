import * as React from 'react';

import {
	createMemoryHistory,
	createRootRoute,
	createRoute,
	createRouter,
	RouterProvider,
} from '@tanstack/react-router';
import { act, fireEvent, render, screen } from '@testing-library/react';

import { RouteNavItem } from './nav-item';

function renderAt(path: string, ui: React.ReactNode) {
	const root = createRootRoute({ component: () => <>{ui}</> });
	const router = createRouter({
		routeTree: root.addChildren([
			createRoute({ getParentRoute: () => root, path: 'extensions', component: () => null }),
		]),
		history: createMemoryHistory({ initialEntries: [path] }),
	});
	render(<RouterProvider router={router} />);
	return router;
}

describe('RouteNavItem', () => {
	it('renders a count as a brand chip with the settings test id', async () => {
		renderAt('/', <RouteNavItem to="/extensions" label="Extensions" badge={3} />);

		const link = await screen.findByTestId('settings-nav-extensions');
		expect(link.getAttribute('href')).toBe('/extensions');
		expect(screen.getByText('3').className).toContain('wcpos:bg-wp-admin-theme-color');
	});

	it('renders error and warning counts as critical and warning chips', async () => {
		renderAt('/', <RouteNavItem to="/logs" label="Logs" badge={{ error: 2, warning: 1 }} />);

		await screen.findByTestId('settings-nav-logs');
		expect(screen.getByText('2').className).toContain('wcpos:bg-red-100');
		expect(screen.getByText('1').className).toContain('wcpos:bg-amber-50');
	});

	it('renders nothing for a zero count', async () => {
		renderAt('/', <RouteNavItem to="/extensions" label="Extensions" badge={0} />);

		const link = await screen.findByTestId('settings-nav-extensions');
		expect(link.querySelector('span')).toBeNull();
	});

	it('calls the onClick passed by the sidebar when the link is clicked', async () => {
		const onClick = vi.fn();
		const router = renderAt(
			'/',
			<RouteNavItem to="/extensions" label="Extensions" onClick={onClick} />
		);

		const link = await screen.findByTestId('settings-nav-extensions');
		await act(async () => {
			fireEvent.click(link);
		});

		expect(onClick).toHaveBeenCalledTimes(1);
		expect(router.state.location.pathname).toBe('/extensions');
	});
});
