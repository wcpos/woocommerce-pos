import { createBrowserHistory, parseHref } from '@tanstack/history';
import { createRouter, createRootRoute, createRoute, useSearch } from '@tanstack/react-router';
import apiFetch from '@wordpress/api-fetch';

import { GalleryLayout } from './layouts/gallery-layout';
import { queryClient } from './query-client';
import { CustomerEmailDesigns } from './screens/customer-email-designs';
import { GalleryGrid } from './screens/gallery-grid';

const rootRoute = createRootRoute({
	component: GalleryLayout,
});

function TemplateScreen() {
	const { type } = useSearch({ from: '/' });
	return type === 'email' ? <CustomerEmailDesigns /> : <GalleryGrid />;
}

const indexRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/',
	validateSearch: (
		search: Record<string, unknown>
	): { type: 'receipt' | 'display' | 'closure' | 'report' | 'email' } => ({
		type:
			search.type === 'display' ||
			search.type === 'closure' ||
			search.type === 'report' ||
			search.type === 'email'
				? search.type
				: 'receipt',
	}),
	loaderDeps: ({ search: { type } }) => ({ type }),
	loader: ({ deps: { type } }) => {
		if (type === 'email') return;
		queryClient.prefetchQuery({
			queryKey: ['templates', type],
			queryFn: () =>
				apiFetch({
					path: `wcpos/v1/templates?wcpos=1&type=${type}`,
					method: 'GET',
				}),
			retry: 1,
		});
		queryClient.prefetchQuery({
			queryKey: ['gallery-templates', type],
			queryFn: () =>
				apiFetch({
					path: `wcpos/v1/templates/gallery?wcpos=1&type=${type}`,
					method: 'GET',
				}),
			retry: 1,
		});
	},
	component: TemplateScreen,
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
