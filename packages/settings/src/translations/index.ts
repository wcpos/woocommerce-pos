import { createI18nInstance, Trans } from '@wcpos/i18n';

import en from './locales/en/wp-admin-settings.json';

const { i18n, i18nPromise, t } = createI18nInstance({
	namespace: 'wp-admin-settings',
	project: 'woocommerce-pos',
	resources: {
		en: { 'wp-admin-settings': en },
	},
});

export { t, Trans, i18nPromise, i18n };
