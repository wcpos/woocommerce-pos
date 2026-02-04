import { createI18nInstance, Trans } from '@wcpos/i18n';

const { i18n, i18nPromise, t } = createI18nInstance({
	namespace: 'wp-admin-settings',
	project: 'woocommerce-pos',
});

export { t, Trans, i18nPromise, i18n };
