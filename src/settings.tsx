import * as React from 'react';
import { render } from '@wordpress/element';
import { ErrorBoundary } from 'react-error-boundary';
import { __ } from '@wordpress/i18n';
import { TabPanel, Notice } from '@wordpress/components';
import Header from './settings/header';
import General, { GeneralSettingsProps } from './settings/general';
import Checkout, { CheckoutSettingsProps } from './settings/checkout';
import Access from './settings/access';
import License from './settings/license';
import Footer from './settings/footer';
import Error from './components/error';
import { get } from 'lodash';

import './settings.scss';

interface AppProps {
	initialSettings: {
		general: GeneralSettingsProps;
		checkout: CheckoutSettingsProps;
	};
}

export interface NoticeProps {
	type?: 'error' | 'info' | 'success';
	message: string;
}

const App = ({ initialSettings }: AppProps) => {
	const [notice, setNotice] = React.useState<NoticeProps | null>(null);

	return (
		<>
			<Header />
			<TabPanel
				className="woocommerce-pos-settings-main"
				tabs={[
					{ name: 'general', title: 'General', Component: General },
					{ name: 'checkout', title: 'Checkout', Component: Checkout },
					{ name: 'access', title: 'POS Access', Component: Access },
					{ name: 'license', title: 'Pro License', Component: License },
				]}
			>
				{({ Component, title, name }) => (
					<ErrorBoundary
						FallbackComponent={Error}
						onReset={() => {
							console.log('reset');
						}}
					>
						{notice && (
							<Notice status={notice.type} onRemove={() => setNotice(null)}>
								{notice.message}
							</Notice>
						)}
						<Component
							title={title}
							initialSettings={get(initialSettings, name)}
							setNotice={setNotice}
						/>
					</ErrorBoundary>
				)}
			</TabPanel>
			<Footer />
		</>
	);
};

render(
	<App initialSettings={get(window, 'wcpos.settings')} />,
	document.getElementById('woocommerce-pos-settings')
);
