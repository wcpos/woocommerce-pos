import { createI18nInstance } from '@wcpos/i18n';

import en from './locales/en/wp-admin-analytics.json';

const { i18n, i18nPromise, t } = createI18nInstance({
	namespace: 'wp-admin-analytics',
	project: 'woocommerce-pos',
	resources: {
		en: { 'wp-admin-analytics': en },
	},
});

export { t, i18nPromise, i18n };
