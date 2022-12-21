import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import classNames from 'classnames';
import { get, isInteger } from 'lodash';
import { ErrorBoundary } from 'react-error-boundary';

import Error from './components/error';
import Notice from './components/notice';
import { SnackbarProvider } from './components/snackbar';
import Tabs from './components/tabs';
import useNotices, { NoticesProvider } from './hooks/use-notices';
import Access, { AccessSettingsProps } from './screens/access';
import Checkout, { CheckoutSettingsProps } from './screens/checkout';
import Footer from './screens/footer';
import General, { GeneralSettingsProps } from './screens/general';
import Header from './screens/header';
import License, { LicenseSettingsProps } from './screens/license';
import { t } from './translations';

import './index.css';

// Create a client
const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			suspense: true,
		},
	},
});

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

const components = {
	general: General,
	checkout: Checkout,
	access: Access,
	license: License,
};

const App = () => {
	// const { notice, snackbar, setNotice, setSnackbar } = useNotices();
	const [index, setIndex] = React.useState(0);

	const renderScene = ({ route }) => {
		const Component = components[route.key];

		return (
			<ErrorBoundary FallbackComponent={Error}>
				{/* {notice && (
					<Notice status={notice.type} onRemove={() => setNotice(null)}>
						{notice.message}
					</Notice>
				)} */}
				<React.Suspense fallback={<></>}>
					<Component />
				</React.Suspense>
			</ErrorBoundary>
		);
	};

	const routes = [
		{ key: 'general', title: t('General', { _tags: 'wp-admin-settings ' }) },
		{ key: 'checkout', title: t('Checkout', { _tags: 'wp-admin-settings' }) },
		{ key: 'access', title: t('Access', { _tags: 'wp-admin-settings' }) },
		{ key: 'license', title: t('License', { _tags: 'wp-admin-settings' }) },
	];

	return (
		<div className="wcpos-container wcpos-mx-auto wcpos-max-w-screen-md wcpos-py-0 md:wcpos-py-4 md:wcpos-pr-4 wcpos-space-y-4">
			<div className="wcpos-bg-white wcpos-rounded-lg">
				<Header />
				<Tabs<typeof routes[number]>
					renderScene={renderScene}
					navigationState={{ index, routes }}
					onIndexChange={setIndex}
				/>
			</div>
			<div className="wcpos-bg-white wcpos-rounded-lg wcpos-py-4">
				<Footer />
			</div>
		</div>
	);
};

render(
	<QueryClientProvider client={queryClient}>
		<React.Suspense fallback={<p>Loading app...</p>}>
			<NoticesProvider>
				<SnackbarProvider>
					<App />
				</SnackbarProvider>
			</NoticesProvider>
		</React.Suspense>
		<ReactQueryDevtools initialIsOpen={true} />
	</QueryClientProvider>,
	document.getElementById('woocommerce-pos-settings')
);
