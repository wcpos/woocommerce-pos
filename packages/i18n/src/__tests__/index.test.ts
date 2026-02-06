/**
 * Tests for the i18n fallback chain.
 *
 * Verifies that translation resolution follows the correct priority:
 *   1. Exact locale (e.g. fr_CA)
 *   2. Language-only fallback (e.g. fr)
 *   3. Bundled English (en)
 */
import i18n from 'i18next';

// Mock backends so no real network requests are made.
// ChainedBackend always reports "not found", simulating CDN unavailable.
jest.mock('i18next-chained-backend', () => ({
	__esModule: true,
	default: {
		type: 'backend',
		init: jest.fn(),
		read: jest.fn(
			(_lang: string, _ns: string, callback: (err: Error | null, data: boolean) => void) => {
				callback(new Error('mocked: CDN unavailable'), false);
			}
		),
	},
}));

jest.mock('i18next-http-backend', () => ({
	__esModule: true,
	default: { type: 'backend', init: jest.fn(), read: jest.fn() },
}));

jest.mock('i18next-localstorage-backend', () => ({
	__esModule: true,
	default: { type: 'backend', init: jest.fn(), read: jest.fn() },
}));

jest.mock('react-i18next', () => ({
	initReactI18next: { type: '3rdParty', init: jest.fn() },
	Trans: jest.fn(),
}));

import { createI18nInstance, detectLocale } from '../index';

const NS = 'test-ns';

const enTranslations: Record<string, string> = {
	'common.hello': 'Hello',
	'common.settings': 'Settings',
};

const frTranslations: Record<string, string> = {
	'common.hello': 'Bonjour',
	'common.settings': 'Paramètres',
};

const frCATranslations: Record<string, string> = {
	'common.hello': 'Allô',
};

// ---------------------------------------------------------------------------
// detectLocale – maps HTML lang attribute to the internal locale code
// ---------------------------------------------------------------------------
describe('detectLocale', () => {
	afterEach(() => {
		document.documentElement.removeAttribute('lang');
	});

	it('returns fr_CA for lang="fr-CA"', () => {
		document.documentElement.setAttribute('lang', 'fr-CA');
		expect(detectLocale()).toBe('fr_CA');
	});

	it('returns fr_FR for lang="fr"', () => {
		document.documentElement.setAttribute('lang', 'fr');
		expect(detectLocale()).toBe('fr_FR');
	});

	it('returns en_US when no lang attribute is set', () => {
		expect(detectLocale()).toBe('en_US');
	});

	it('falls back to language-only match for unknown regional variant', () => {
		// "de-AT" is not in locales.json, but "de" is → should match "de"
		document.documentElement.setAttribute('lang', 'de-AT');
		const locale = detectLocale();
		// Should resolve to the "de" entry's locale
		expect(locale).toBe('de_DE');
	});
});

// ---------------------------------------------------------------------------
// i18next resolution – verifies the config options produce the right chain
// ---------------------------------------------------------------------------
describe('i18next resolution with underscore locales', () => {
	it('uses fr_CA when fr_CA resources exist', async () => {
		const instance = i18n.createInstance();
		await instance.init({
			lng: 'fr_CA',
			fallbackLng: 'en',
			ns: [NS],
			defaultNS: NS,
			keySeparator: false,
			nsSeparator: false,
			resources: {
				en: { [NS]: enTranslations },
				fr: { [NS]: frTranslations },
				fr_CA: { [NS]: frCATranslations },
			},
		});

		expect(instance.t('common.hello')).toBe('Allô');
	});

	it('falls back to fr when fr_CA is not available', async () => {
		const instance = i18n.createInstance();
		await instance.init({
			lng: 'fr_CA',
			fallbackLng: 'en',
			ns: [NS],
			defaultNS: NS,
			keySeparator: false,
			nsSeparator: false,
			resources: {
				en: { [NS]: enTranslations },
				fr: { [NS]: frTranslations },
			},
		});

		expect(instance.t('common.hello')).toBe('Bonjour');
	});

	it('falls back to en when neither fr_CA nor fr are available', async () => {
		const instance = i18n.createInstance();
		await instance.init({
			lng: 'fr_CA',
			fallbackLng: 'en',
			ns: [NS],
			defaultNS: NS,
			keySeparator: false,
			nsSeparator: false,
			resources: {
				en: { [NS]: enTranslations },
			},
		});

		expect(instance.t('common.hello')).toBe('Hello');
	});

	it('uses fr_CA key, then fr for missing keys, then en', async () => {
		const instance = i18n.createInstance();
		await instance.init({
			lng: 'fr_CA',
			fallbackLng: 'en',
			ns: [NS],
			defaultNS: NS,
			keySeparator: false,
			nsSeparator: false,
			resources: {
				en: { [NS]: enTranslations },
				fr: { [NS]: frTranslations },
				fr_CA: { [NS]: frCATranslations }, // only has common.hello
			},
		});

		// fr_CA has this key
		expect(instance.t('common.hello')).toBe('Allô');
		// fr_CA doesn't, falls through to fr
		expect(instance.t('common.settings')).toBe('Paramètres');
	});
});

// ---------------------------------------------------------------------------
// createI18nInstance – integration test through the factory function
// ---------------------------------------------------------------------------
describe('createI18nInstance fallback to bundled English', () => {
	beforeEach(() => {
		document.documentElement.setAttribute('lang', 'fr-CA');
	});

	afterEach(() => {
		document.documentElement.removeAttribute('lang');
	});

	it('returns bundled English when CDN is unavailable', async () => {
		const { i18nPromise, t } = createI18nInstance({
			namespace: NS,
			project: 'woocommerce-pos',
			resources: {
				en: { [NS]: enTranslations },
			},
		});

		await i18nPromise;
		expect(t('common.hello')).toBe('Hello');
		expect(t('common.settings')).toBe('Settings');
	});
});
