import {
	createRouter,
	createRootRoute,
	createRoute,
	createHashHistory,
} from '@tanstack/react-router';

const rootRoute = createRootRoute({
	component: () => <div>Gallery placeholder</div>,
});

const indexRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/',
	component: () => null,
});

const routeTree = rootRoute.addChildren([indexRoute]);

export const router = createRouter({
	routeTree,
	basepath: '/',
	history: createHashHistory(),
});
