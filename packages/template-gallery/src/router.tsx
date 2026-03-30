import {
	createRouter,
	createRootRoute,
	createRoute,
	createHashHistory,
} from '@tanstack/react-router';
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
	loader: () => {
		queryClient.prefetchQuery({
			queryKey: ['templates', 'receipt'],
			queryFn: () =>
				apiFetch({ path: 'wcpos/v1/templates?wcpos=1&type=receipt', method: 'GET' }),
			retry: 1,
		});
		queryClient.prefetchQuery({
			queryKey: ['gallery-templates', 'receipt'],
			queryFn: () =>
				apiFetch({ path: 'wcpos/v1/templates/gallery?wcpos=1&type=receipt', method: 'GET' }),
			retry: 1,
		});
	},
	component: GalleryGrid,
});

const routeTree = rootRoute.addChildren([indexRoute]);

export const router = createRouter({
	routeTree,
	basepath: '/',
	history: createHashHistory(),
});
