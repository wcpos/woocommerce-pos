import {
	createRouter,
	createRootRoute,
	createRoute,
	createHashHistory,
} from '@tanstack/react-router';

import { GalleryLayout } from './layouts/gallery-layout';

const rootRoute = createRootRoute({
	component: GalleryLayout,
});

const indexRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/',
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
