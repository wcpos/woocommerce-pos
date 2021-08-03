import * as React from 'react';
import { render } from '@wordpress/element';
import { ErrorBoundary } from 'react-error-boundary';
import { __ } from '@wordpress/i18n';
import { TabPanel, Notice } from '@wordpress/components';
import Header from './settings/header';
import General, { GeneralSettingsProps } from './settings/general';
import Checkout, { CheckoutSettingsProps } from './settings/checkout';
import Access, { AccessSettingsProps } from './settings/access';
import License from './settings/license';
import Footer from './settings/footer';
import Error from './components/error';
import { get } from 'lodash';

import './settings.scss';

export interface HydrateProps {
	settings: {
		general: GeneralSettingsProps;
		checkout: CheckoutSettingsProps;
		access: AccessSettingsProps;
	};
	barcode_fields: string[];
	order_statuses: Record<string, string>;
}

export interface NoticeProps {
	type?: 'error' | 'info' | 'success';
	message: string;
}

interface AppProps {
	hydrate: HydrateProps;
}

const App = ({ hydrate }: AppProps) => {
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
				initialTabName="license"
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
							initialSettings={get(hydrate, ['settings', name])}
							setNotice={setNotice}
							hydrate={hydrate}
						/>
					</ErrorBoundary>
				)}
			</TabPanel>
			<Footer />
		</>
	);
};

render(<App hydrate={get(window, 'wcpos')} />, document.getElementById('woocommerce-pos-settings'));
