import {
	createRouter,
	createRootRoute,
	createRoute,
	redirect,
	createHashHistory,
	Outlet,
} from '@tanstack/react-router';
import apiFetch from '@wordpress/api-fetch';

import { queryClient } from './query-client';

// Import page components — these are the existing screen components
import GeneralPage from './screens/general';
import CheckoutPage from './screens/checkout';
import AccessPage from './screens/access';
import SessionsPage from './screens/sessions';
import LicensePage from './screens/license';

// Root route — renders Outlet so child routes display.
// The full RootLayout component gets added in Task 5.
const rootRoute = createRootRoute({
	component: () => <Outlet />,
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
		queryFn: () =>
			apiFetch({ path: `wcpos/v1/settings/${id}?wcpos=1`, method: 'GET' }),
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
	licenseRoute,
]);

export const router = createRouter({
	routeTree,
	basepath: '/',
	history: createHashHistory(),
});
