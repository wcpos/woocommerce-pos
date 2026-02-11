import { QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { RouterProvider } from '@tanstack/react-router';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from 'react-error-boundary';

import ErrorFallback from './components/error';
import { SnackbarProvider } from './components/snackbar';
import { NoticesProvider } from './hooks/use-notices';
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

const el = document.getElementById('woocommerce-pos-settings');
if (el) {
	createRoot(el).render(<Root />);
}
