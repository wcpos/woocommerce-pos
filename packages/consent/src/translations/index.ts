import { createI18nInstance } from '@wcpos/i18n';

import type { ConsentCopy } from '../api';
import en from './locales/en/wp-admin-consent.json';

const { i18n, i18nPromise, t, Trans } = createI18nInstance({
	namespace: 'wp-admin-consent',
	project: 'woocommerce-pos',
	resources: {
		en: { 'wp-admin-consent': en },
	},
});

function consentText(copy: ConsentCopy | undefined, key: keyof ConsentCopy, fallback: string): string {
	const value = copy?.[key];

	return typeof value === 'string' && value !== '' ? value : t(fallback);
}

export { t, Trans, i18nPromise, i18n, consentText };
