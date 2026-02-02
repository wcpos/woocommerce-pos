import * as React from 'react';

import { Notice } from '@wordpress/components';

import { t, i18nPromise } from './translations';

const globalHooks = window.wp && window.wp.hooks;

const WrappedReport = ({ Component, ...props }) => {
	const [isReady, setIsReady] = React.useState(false);

	React.useEffect(() => {
		i18nPromise.then(() => setIsReady(true));
	}, []);

	return (
		<>
			<Notice isDismissible={false} className="woocommerce-pos-upgrade-notice">
				{t('Do you want analytics for your POS orders?')}{' '}
				<a target="_blank" href="https://wcpos.com/pro">
					{t('Upgrade to WCPOS Pro')}
				</a>
				.
			</Notice>
			<Component {...props} />
		</>
	);
};

if (globalHooks) {
	globalHooks.addFilter('woocommerce_admin_reports_list', 'woocommerce-pos', (pages) => {
		return pages.map((item) => {
			if (item.report === 'orders') {
				return {
					...item,
					component: (props) => <WrappedReport Component={item.component} {...props} />,
				};
			}
			return item;
		});
	});
}
