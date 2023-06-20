import * as Transifex from '@transifex/native';

import localesData from './locales.json';

const tx = Transifex.tx;
const t = Transifex.t;

tx.init({
	token: '1/09853773ef9cda3be96c8c451857172f26927c0f',
	filterTags: 'wp-admin-analytics',
});

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

const txPromise = tx.setCurrentLocale(locale).catch(console.error);

export { tx, t, txPromise };
