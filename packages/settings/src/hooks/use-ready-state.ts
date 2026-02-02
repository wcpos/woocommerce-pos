import * as React from 'react';

import { useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import useNotices from '../hooks/use-notices';
import { i18nPromise } from '../translations';

interface Props {
	initialScreen: string;
}

const useReadyState = ({ initialScreen }: Props) => {
	const [isReady, setIsReady] = React.useState(false);
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();

	const prefetchSettings = React.useCallback(() => {
		// Skip prefetch for sessions tab as it uses different API endpoints
		if (initialScreen === 'sessions') {
			return Promise.resolve();
		}

		return queryClient.prefetchQuery({
			queryKey: [initialScreen],
			queryFn: async () => {
				const response = await apiFetch<Record<string, unknown>>({
					path: `wcpos/v1/settings/${initialScreen}?wcpos=1`,
					method: 'GET',
				}).catch((err) => {
					console.error(err);
					return err;
				});

				// if we have an error response, set the notice
				if (response?.code && response?.message) {
					setNotice({ type: 'error', message: response?.message });
				}

				return response;
			},
		});
	}, [initialScreen, queryClient, setNotice]);

	React.useEffect(() => {
		Promise.allSettled([i18nPromise, prefetchSettings()]).then(() => {
			setIsReady(true);
		});
	}, [prefetchSettings]);

	return { isReady };
};

export default useReadyState;
