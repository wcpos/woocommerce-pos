import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { createRoot, render } from '@wordpress/element';
import { getFragment, isValidFragment } from '@wordpress/url';
import { ErrorBoundary } from 'react-error-boundary';

import Error from './components/error';
import { SnackbarProvider } from './components/snackbar';
import { NoticesProvider } from './hooks/use-notices';
import useReadyState from './hooks/use-ready-state';
import Main, { ScreenKeys } from './screens';

import './index.css';

// Create a client
const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			suspense: true,
			staleTime: 10 * 60 * 1000, // 10 minutes
		},
	},
});

const App = () => {
	const fragment = getFragment(window.location.href) || '';
	const initialScreen = isValidFragment(fragment)
		? (fragment.replace(/^#/, '') as ScreenKeys)
		: 'general';

	const { isReady } = useReadyState({ initialScreen });

	if (!isReady) {
		return null;
	}

	return (
		<React.Suspense fallback={<p>Loading app...</p>}>
			<NoticesProvider>
				<SnackbarProvider>
					<Main initialScreen={initialScreen} />
				</SnackbarProvider>
			</NoticesProvider>
		</React.Suspense>
	);
};

const Root = () => {
	return (
		<ErrorBoundary FallbackComponent={Error}>
			<QueryClientProvider client={queryClient}>
				<App />
				<ReactQueryDevtools initialIsOpen={true} />
			</QueryClientProvider>
		</ErrorBoundary>
	);
};

const el = document.getElementById('woocommerce-pos-settings');

if (createRoot) {
	createRoot(el).render(<Root />);
} else {
	render(<Root />, el);
}
