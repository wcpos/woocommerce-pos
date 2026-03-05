import {
	createRouter,
	createRootRoute,
	createRoute,
	createHashHistory,
} from '@tanstack/react-router';
import apiFetch from '@wordpress/api-fetch';

import { GalleryLayout } from './layouts/gallery-layout';
import { queryClient } from './query-client';

const rootRoute = createRootRoute({
	component: GalleryLayout,
});

const indexRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/',
	loader: async () => {
		await Promise.all([
			queryClient.ensureQueryData({
				queryKey: ['templates', 'receipt'],
				queryFn: () =>
					apiFetch({ path: 'wcpos/v1/templates?wcpos=1&type=receipt', method: 'GET' }),
			}),
			queryClient.ensureQueryData({
				queryKey: ['gallery-templates', 'receipt'],
				queryFn: () =>
					apiFetch({ path: 'wcpos/v1/templates/gallery?wcpos=1&type=receipt', method: 'GET' }),
			}),
		]);
	},
	component: () => (
		<div className="wcpos:text-gray-400 wcpos:text-center wcpos:py-12">
			Gallery grid loading...
		</div>
	),
});

const routeTree = rootRoute.addChildren([indexRoute]);

export const router = createRouter({
	routeTree,
	basepath: '/',
	history: createHashHistory(),
});
