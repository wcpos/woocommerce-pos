import { QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { RouterProvider } from '@tanstack/react-router';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from 'react-error-boundary';

import { SnackbarProvider } from '@wcpos/ui';

import ErrorFallback from './components/error';
import { NoticesProvider } from './hooks/use-notices';
import { captureSettingsSectionViewed } from './lib/analytics';
import { queryClient } from './query-client';
import { router } from './router';

import './index.css';

function Root() {
	return (
		<ErrorBoundary FallbackComponent={ErrorFallback}>
			<QueryClientProvider client={queryClient}>
				<NoticesProvider>
					<SnackbarProvider>
						<RouterProvider router={router} />
					</SnackbarProvider>
				</NoticesProvider>
				<ReactQueryDevtools initialIsOpen={false} />
			</QueryClientProvider>
		</ErrorBoundary>
	);
}

// One subscription covers every section: the initial load (which redirects to
// /general) and each later navigation. Hooking the router rather than each
// screen means a new section is instrumented the moment it gets a route.
router.subscribe('onResolved', ({ toLocation }) => {
	captureSettingsSectionViewed(toLocation.pathname.replace(/^\/+/, ''));
});

const el = document.getElementById('woocommerce-pos-settings');
if (el) {
	createRoot(el).render(<Root />);
}
