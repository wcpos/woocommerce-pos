import * as React from 'react';
import { render } from '@wordpress/element';
import { ErrorBoundary } from 'react-error-boundary';
import { __ } from '@wordpress/i18n';
import { TabPanel, Notice, SnackbarList } from '@wordpress/components';
import { get } from 'lodash';
import Header from './settings/header';
import General, { GeneralSettingsProps } from './settings/general';
import Checkout, { CheckoutSettingsProps } from './settings/checkout';
import Access, { AccessSettingsProps } from './settings/access';
import License, { LicenseSettingsProps } from './settings/license';
import Footer from './settings/footer';
import Error from './components/error';
import useNotices, { NoticesProvider } from './hooks/use-notices';

import './settings.css';

export interface HydrateProps {
	settings: {
		general: GeneralSettingsProps;
		checkout: CheckoutSettingsProps;
		access: AccessSettingsProps;
		license: LicenseSettingsProps;
	};
	barcode_fields: string[];
	order_statuses: Record<string, string>;
}

interface AppProps {
	hydrate: HydrateProps;
}

const App = ({ hydrate }: AppProps) => {
	const { notice, snackbars, setNotice, setSnackbars } = useNotices();

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
				initialTabName="general"
			>
				{({ Component }) => (
					<ErrorBoundary FallbackComponent={Error}>
						{notice && (
							<Notice status={notice.type} onRemove={() => setNotice(null)}>
								{notice.message}
							</Notice>
						)}
						<Component hydrate={hydrate} />
					</ErrorBoundary>
				)}
			</TabPanel>
			<Footer />
			<div id="woocommerce-pos-settings-snackbars">
				<SnackbarList
					notices={snackbars}
					onRemove={() => {
						setSnackbars([]);
					}}
				/>
			</div>
		</>
	);
};

render(
	<NoticesProvider>
		<App hydrate={get(window, 'wcpos')} />
	</NoticesProvider>,
	document.getElementById('woocommerce-pos-settings')
);
