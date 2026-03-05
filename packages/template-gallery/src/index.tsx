import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from 'react-error-boundary';

import { queryClient } from './query-client';
import { router } from './router';

import './index.css';

function Root() {
	return (
		<ErrorBoundary
			fallbackRender={({ error }) => (
				<div className="wcpos:p-6 wcpos:text-red-600">
					<h2>Template Gallery failed to load</h2>
					<pre>{error?.message}</pre>
				</div>
			)}
		>
			<QueryClientProvider client={queryClient}>
				<RouterProvider router={router} />
			</QueryClientProvider>
		</ErrorBoundary>
	);
}

const el = document.getElementById('wcpos-template-gallery');
if (el) {
	createRoot(el).render(<Root />);
}
