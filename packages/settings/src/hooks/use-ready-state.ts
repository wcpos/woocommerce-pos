import * as React from 'react';

import { useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import useNotices from '../hooks/use-notices';
import { tx } from '../translations';

interface Props {
	initialScreen: string;
}

const useReadyState = ({ initialScreen }: Props) => {
	const [isReady, setIsReady] = React.useState(false);
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();

	const fetchLanguage = React.useCallback(() => {
		return tx.setCurrentLocale('es_ES').catch(console.error);
	}, []);

	const prefetchSettings = React.useCallback(() => {
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
		Promise.allSettled([fetchLanguage(), prefetchSettings()]).then(() => {
			setIsReady(true);
		});
	}, [fetchLanguage, prefetchSettings]);

	return { isReady };
};

export default useReadyState;
