import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { render } from '@wordpress/element';
import { getFragment, isValidFragment } from '@wordpress/url';

import { SnackbarProvider } from './components/snackbar';
import useNotices, { NoticesProvider } from './hooks/use-notices';
import useReadyState from './hooks/use-ready-state';
import Main from './screens';

import './index.css';

// Create a client
const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			suspense: true,
		},
	},
});

const App = () => {
	const fragment = getFragment(window.location.href) || '';
	// @TODO - validate fragment against routes
	const initialScreen = isValidFragment(fragment) ? fragment.replace(/^#/, '') : 'general';

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

render(
	<QueryClientProvider client={queryClient}>
		<App />
		<ReactQueryDevtools initialIsOpen={true} />
	</QueryClientProvider>,
	document.getElementById('woocommerce-pos-settings')
);
