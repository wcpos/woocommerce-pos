import * as React from 'react';
import { render } from '@wordpress/element';
import classNames from 'classnames';
import { ErrorBoundary } from 'react-error-boundary';
import { __ } from '@wordpress/i18n';
import { get, isInteger } from 'lodash';
import Header from './components/header';
import General, { GeneralSettingsProps } from './settings/general';
import Checkout, { CheckoutSettingsProps } from './settings/checkout';
import Access, { AccessSettingsProps } from './settings/access';
import License, { LicenseSettingsProps } from './settings/license';
import Footer from './components/footer';
import Error from './components/error';
import useNotices, { NoticesProvider } from './hooks/use-notices';
import Notice from './components/notice';
import Snackbar from './components/snackbar';
import Tabs from './components/tabs';

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
	const { notice, snackbar, setNotice, setSnackbar } = useNotices();

	return (
		<div className="container mx-auto max-w-screen-md py-0 md:py-4 md:pr-4 space-y-4">
			<div className="bg-white rounded-lg">
				<Header />
				<Tabs
					tabs={[
						{ name: 'general', title: 'General', Component: General },
						{ name: 'checkout', title: 'Checkout', Component: Checkout },
						{ name: 'access', title: 'POS Access', Component: Access },
						{ name: 'license', title: 'Pro License', Component: License },
					]}
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
				</Tabs>
			</div>
			<Footer />
			<div className="fixed w-48 h-48 bottom-8 pointer-events-none flex flex-col justify-end">
				<Snackbar
					message={snackbar?.message}
					onRemove={() => setSnackbar(null)}
					timeout={snackbar?.timeout}
				/>
			</div>
		</div>
	);
};

render(
	<NoticesProvider>
		<App hydrate={get(window, 'wcpos')} />
	</NoticesProvider>,
	document.getElementById('woocommerce-pos-settings')
);
