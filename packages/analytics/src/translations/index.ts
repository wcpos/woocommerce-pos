import i18n from 'i18next';
import HttpBackend from 'i18next-http-backend';
import { initReactI18next } from 'react-i18next';

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
	.use(HttpBackend)
	.use(initReactI18next)
	.init({
		lng: locale,
		fallbackLng: false,
		ns: ['wp-admin-analytics'],
		defaultNS: 'wp-admin-analytics',
		keySeparator: false,
		nsSeparator: false,
		interpolation: {
			escapeValue: false,
			prefix: '{',
			suffix: '}',
		},
		backend: {
			loadPath: `https://cdn.jsdelivr.net/gh/wcpos/translations@v${translationVersion}/translations/js/{{lng}}/{{ns}}.json`,
		},
	});

const t = i18n.t.bind(i18n);

export { t, i18nPromise, i18n };
