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

const tabs = [
	{ name: 'general', title: 'General', Component: General },
	{ name: 'checkout', title: 'Checkout', Component: Checkout },
	{ name: 'access', title: 'POS Access', Component: Access },
	{ name: 'license', title: 'Pro License', Component: License },
];

const App = ({ hydrate }: AppProps) => {
	const { notice, snackbars, setNotice, setSnackbars } = useNotices();

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
				{/* <Tab.Group defaultIndex={0} manual>
					<Tab.List className="flex space-x-4 justify-center">
						{tabs.map((tab) => (
							<Tab
								key={tab.name}
								className={({ selected }) =>
									classNames('text-base px-4 py-2', selected ? 'border-b-4' : 'border-b-4')
								}
							>
								{tab.title}
							</Tab>
						))}
					</Tab.List>
					<Tab.Panels className="mt-2">
						{({ selectedIndex }) => {
							if (isInteger(selectedIndex)) {
								const Component = tabs[selectedIndex || 0].Component;
								return (
									<ErrorBoundary FallbackComponent={Error}>
										{notice && (
											<Notice status={notice.type} onRemove={() => setNotice(null)}>
												<p>{notice.message}</p>
											</Notice>
										)}
										<Tab.Panel>
											<Component hydrate={hydrate} />
										</Tab.Panel>
									</ErrorBoundary>
								);
							}
						}}
					</Tab.Panels>
				</Tab.Group> */}
			</div>
			<Footer />
		</div>
	);
};

render(
	<NoticesProvider>
		<App hydrate={get(window, 'wcpos')} />
	</NoticesProvider>,
	document.getElementById('woocommerce-pos-settings')
);
