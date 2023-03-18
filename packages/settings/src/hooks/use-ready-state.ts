import * as React from 'react';

import { useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import useNotices from '../hooks/use-notices';
import { tx } from '../translations';
import localesData from '../translations/locales.json';

interface Locale {
	name: string;
	nativeName?: string;
	code: string;
	locale: string;
}

interface Locales {
	[key: string]: Locale;
}

interface Props {
	initialScreen: string;
}

const locales: Locales = localesData;
const htmlElement = document.documentElement;
const lang = htmlElement.getAttribute('lang') || 'en';
const { locale } = locales[lang.toLowerCase()] || locales[lang.split('-')[0]] || locales['en'];

const useReadyState = ({ initialScreen }: Props) => {
	const [isReady, setIsReady] = React.useState(false);
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();

	const fetchLanguage = React.useCallback(() => {
		return tx.setCurrentLocale(locale).catch(console.error);
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
