import i18n from 'i18next';
import ChainedBackend from 'i18next-chained-backend';
import HttpBackend from 'i18next-http-backend';
import LocalStorageBackend from 'i18next-localstorage-backend';
import { initReactI18next, Trans } from 'react-i18next';

import localesData from './locales.json';

interface Locale {
	name: string;
	nativeName?: string;
	code: string;
	locale: string;
}

interface Locales {
	[key: string]: Locale;
}

const locales: Locales = localesData;
const htmlElement = document.documentElement;
const lang = htmlElement.getAttribute('lang') || 'en';
const { locale } = locales[lang.toLowerCase()] || locales[lang.split('-')[0]] || locales['en'];

declare global {
	interface Window {
		wcpos?: {
			settings?: Record<string, unknown>;
			translationVersion?: string;
		};
	}
}

const translationVersion = window.wcpos?.translationVersion || '0.1.0';

const i18nPromise = i18n
	.use(ChainedBackend)
	.use(initReactI18next)
	.init({
		lng: locale,
		fallbackLng: false,
		ns: ['wp-admin-settings'],
		defaultNS: 'wp-admin-settings',
		keySeparator: false,
		nsSeparator: false,
		interpolation: {
			escapeValue: false,
			prefix: '{',
			suffix: '}',
		},
		backend: {
			backends: [LocalStorageBackend, HttpBackend],
			backendOptions: [
				{
					prefix: 'wcpos_i18n_',
					expirationTime: 7 * 24 * 60 * 60 * 1000, // 7 days
					defaultVersion: translationVersion,
				},
				{
					loadPath: `https://cdn.jsdelivr.net/gh/wcpos/translations@v${translationVersion}/translations/js/{{lng}}/{{ns}}.json`,
				},
			],
		},
	});

const t = i18n.t.bind(i18n);

export { t, Trans, i18nPromise, i18n };
