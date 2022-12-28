import * as React from 'react';

import { useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { tx } from '../translations';

interface Props {
	initialScreen: string;
}

const useReadyState = ({ initialScreen }: Props) => {
	const [isReady, setIsReady] = React.useState(false);
	const queryClient = useQueryClient();

	const fetchLanguage = React.useCallback(() => {
		return tx.setCurrentLocale('es_ES').catch(console.error);
	}, []);

	const prefetchSettings = React.useCallback(() => {
		return queryClient.prefetchQuery({
			queryKey: [initialScreen],
			queryFn: async () => {
				const response = await apiFetch({
					path: `wcpos/v1/settings/${initialScreen}?wcpos=1`,
					method: 'GET',
				}).catch((err) => {
					throw new Error(err.message);
				});

				return response;
			},
		});
	}, [initialScreen, queryClient]);

	React.useEffect(() => {
		Promise.allSettled([fetchLanguage(), prefetchSettings()]).then(() => {
			setIsReady(true);
		});
	}, [fetchLanguage, prefetchSettings]);

	return { isReady };
};

export default useReadyState;
