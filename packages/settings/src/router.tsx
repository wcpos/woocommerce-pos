import {
	createRouter,
	createRootRoute,
	createRoute,
	redirect,
	createHashHistory,
} from '@tanstack/react-router';
import apiFetch from '@wordpress/api-fetch';

import { RootLayout } from './layouts/root-layout';
import { queryClient } from './query-client';
import AccessPage from './screens/access';
import CheckoutPage from './screens/checkout';
import ExtensionsPage from './screens/extensions';
import GeneralPage from './screens/general';
import LicensePage from './screens/license';
import LogsPage from './screens/logs';
import SessionsPage from './screens/sessions';

// Root route — renders the full-page layout with sidebar navigation.
const rootRoute = createRootRoute({
	component: RootLayout,
});

const indexRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/',
	beforeLoad: () => {
		throw redirect({ to: '/general' });
	},
});

const settingsLoader = (id: string) => async () => {
	await queryClient.ensureQueryData({
		queryKey: [id],
		queryFn: () => apiFetch({ path: `wcpos/v1/settings/${id}?wcpos=1`, method: 'GET' }),
	});
};

const generalRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/general',
	loader: settingsLoader('general'),
	component: GeneralPage,
});

const checkoutRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/checkout',
	loader: settingsLoader('checkout'),
	component: CheckoutPage,
});

const accessRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/access',
	loader: settingsLoader('access'),
	component: AccessPage,
});

const sessionsRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/sessions',
	component: SessionsPage,
	// No loader — sessions uses different API endpoints
});

const extensionsRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/extensions',
	loader: async () => {
		await queryClient.ensureQueryData({
			queryKey: ['extensions'],
			queryFn: () => apiFetch({ path: 'wcpos/v1/extensions?wcpos=1', method: 'GET' }),
		});
	},
	component: ExtensionsPage,
});

const logsRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/logs',
	component: LogsPage,
});

const licenseRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/license',
	loader: settingsLoader('license'),
	component: LicensePage,
});

const routeTree = rootRoute.addChildren([
	indexRoute,
	generalRoute,
	checkoutRoute,
	accessRoute,
	sessionsRoute,
	extensionsRoute,
	logsRoute,
	licenseRoute,
]);

export const router = createRouter({
	routeTree,
	basepath: '/',
	history: createHashHistory(),
});
