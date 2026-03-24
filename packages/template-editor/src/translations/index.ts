import { createI18nInstance, Trans } from '@wcpos/i18n';

import en from './locales/en/wp-admin-template-editor.json';

const { i18n, i18nPromise, t } = createI18nInstance({
	namespace: 'wp-admin-template-editor',
	project: 'woocommerce-pos',
	resources: {
		en: { 'wp-admin-template-editor': en },
	},
});

export { t, Trans, i18nPromise, i18n };
