import { createI18nInstance, Trans } from '@wcpos/i18n';

import en from './locales/en/wp-admin-template-gallery.json';

const { i18n, i18nPromise, t } = createI18nInstance({
	namespace: 'wp-admin-template-gallery',
	project: 'woocommerce-pos',
	resources: {
		en: { 'wp-admin-template-gallery': en },
	},
});

export { t, Trans, i18nPromise, i18n };
