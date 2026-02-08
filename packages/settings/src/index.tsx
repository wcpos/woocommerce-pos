import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { RouterProvider } from '@tanstack/react-router';
import { ErrorBoundary } from 'react-error-boundary';

import { queryClient } from './query-client';
import { router } from './router';
import ErrorFallback from './components/error';
import { SnackbarProvider } from './components/snackbar';
import { NoticesProvider } from './hooks/use-notices';

import './index.css';

const Root = () => (
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

const el = document.getElementById('woocommerce-pos-settings');
if (el) {
	createRoot(el).render(<Root />);
}
