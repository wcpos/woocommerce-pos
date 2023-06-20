import * as React from 'react';

import { Notice } from '@wordpress/components';

import { t, txPromise } from './translations';

const globalHooks = window.wp && window.wp.hooks;

const WrappedReport = ({ Component, ...props }) => {
	const [isReady, setIsReady] = React.useState(false);

	React.useEffect(() => {
		txPromise.then(() => setIsReady(true));
	}, []);

	return (
		<>
			<Notice isDismissible={false} className="woocommerce-pos-upgrade-notice">
				{t('Do you want analytics for your POS orders?', { _tags: 'wp-admin-analytics' })}{' '}
				<a target="_blank" href="https://wcpos.com/pro">
					{t('Upgrade to WooCommerce POS Pro', { _tags: 'wp-admin-analytics' })}
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
