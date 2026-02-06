import i18n, { i18n as I18nInstance } from 'i18next';
import ChainedBackend from 'i18next-chained-backend';
import HttpBackend from 'i18next-http-backend';
import LocalStorageBackend from 'i18next-localstorage-backend';
import { initReactI18next, Trans } from 'react-i18next';

import localesData from './locales.json';

export interface Locale {
	name: string;
	nativeName?: string;
	code: string;
	locale: string;
}

export interface Locales {
	[key: string]: Locale;
}

export interface CreateI18nOptions {
	namespace: string;
	project: string;
	resources?: Record<string, Record<string, Record<string, string>>>;
}

declare global {
	interface Window {
		wcpos?: {
			settings?: Record<string, unknown>;
			translationVersion?: string;
		};
	}
}

const locales: Locales = localesData;

/**
 * Detect the current locale from the HTML lang attribute.
 */
export function detectLocale(): string {
	const htmlElement = document.documentElement;
	const lang = htmlElement.getAttribute('lang') || 'en';
	const match = locales[lang.toLowerCase()] || locales[lang.split('-')[0]] || locales['en'];
	return match?.locale || 'en_US';
}

/**
 * Get the translation version from window.wcpos.
 */
export function getTranslationVersion(): string {
	return window.wcpos?.translationVersion || '0.1.0';
}

/**
 * Create a configured i18next instance for WCPOS.
 */
export function createI18nInstance({ namespace, project, resources }: CreateI18nOptions): {
	i18n: I18nInstance;
	i18nPromise: Promise<I18nInstance>;
	t: I18nInstance['t'];
} {
	const instance = i18n.createInstance();
	const locale = detectLocale();
	const translationVersion = getTranslationVersion();

	const initOptions: Record<string, unknown> = {
		lng: locale,
		fallbackLng: resources ? 'en' : false,
		ns: [namespace],
		defaultNS: namespace,
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
					loadPath: `https://cdn.jsdelivr.net/gh/wcpos/translations@${translationVersion}/translations/js/{{lng}}/${project}/{{ns}}.json`,
				},
			],
		},
	};

	if (resources) {
		initOptions.resources = resources;
		initOptions.partialBundledLanguages = true;
	}

	const i18nPromise = instance
		.use(ChainedBackend)
		.use(initReactI18next)
		.init(initOptions);

	return {
		i18n: instance,
		i18nPromise,
		t: instance.t.bind(instance),
	};
}

export { Trans, locales };
