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
import TaxIdsPage from './screens/tax-ids';

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

const settingsLoader = (id: string) => () => {
	queryClient.prefetchQuery({
		queryKey: [id],
		queryFn: () => apiFetch({ path: `wcpos/v1/settings/${id}?wcpos=1`, method: 'GET' }),
		retry: 1,
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

const taxIdsRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/tax-ids',
	loader: settingsLoader('tax_ids'),
	component: TaxIdsPage,
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
	loader: () => {
		queryClient.prefetchQuery({
			queryKey: ['extensions'],
			queryFn: () => apiFetch({ path: 'wcpos/v1/extensions?wcpos=1', method: 'GET' }),
			retry: 1,
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
	taxIdsRoute,
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
