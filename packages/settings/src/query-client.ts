import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			staleTime: 10 * 60 * 1000, // 10 minutes
		},
	},
});

// Expose for Pro plugin to invalidate queries after extension actions.
if (typeof window !== 'undefined') {
	(window as any).wcpos = (window as any).wcpos || {};
	(window as any).wcpos.queryClient = queryClient;
}
