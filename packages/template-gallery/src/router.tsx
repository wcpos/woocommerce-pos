import { createBrowserHistory, parseHref } from '@tanstack/history';
import { createRouter, createRootRoute, createRoute } from '@tanstack/react-router';
import apiFetch from '@wordpress/api-fetch';

import { GalleryLayout } from './layouts/gallery-layout';
import { queryClient } from './query-client';
import { GalleryGrid } from './screens/gallery-grid';

const rootRoute = createRootRoute({
	component: GalleryLayout,
});

const indexRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/',
	validateSearch: (
		search: Record<string, unknown>
	): { type: 'receipt' | 'display' | 'closure' } => ({
		type: search.type === 'closure' ? 'closure' : search.type === 'display' ? 'display' : 'receipt',
	}),
	loaderDeps: ({ search: { type } }) => ({ type }),
	loader: ({ deps: { type } }) => {
		queryClient.prefetchQuery({
			queryKey: ['templates', type],
			queryFn: () => apiFetch({ path: `wcpos/v1/templates?wcpos=1&type=${type}`, method: 'GET' }),
			retry: 1,
		});
		queryClient.prefetchQuery({
			queryKey: ['gallery-templates', type],
			queryFn: () =>
				apiFetch({ path: `wcpos/v1/templates/gallery?wcpos=1&type=${type}`, method: 'GET' }),
			retry: 1,
		});
	},
	component: GalleryGrid,
});

const routeTree = rootRoute.addChildren([indexRoute]);

export const router = createRouter({
	routeTree,
	basepath: '/',
	// WordPress owns the outer query string; hash history must not append it to the route.
	history: createBrowserHistory({
		window,
		parseLocation: () => parseHref(window.location.hash.slice(1) || '/', window.history.state),
		createHref: (href) => `${window.location.pathname}${window.location.search}#${href}`,
	}),
});

declare module '@tanstack/react-router' {
	interface Register {
		router: typeof router;
	}
}
