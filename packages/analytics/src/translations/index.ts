import { createI18nInstance } from '@wcpos/i18n';

const { i18n, i18nPromise, t } = createI18nInstance({
	namespace: 'wp-admin-analytics',
	project: 'woocommerce-pos-pro',
});

export { t, i18nPromise, i18n };
