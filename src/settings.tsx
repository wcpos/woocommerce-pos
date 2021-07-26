import * as React from 'react';
import { render } from '@wordpress/element';
import { ErrorBoundary } from 'react-error-boundary';
import { __ } from '@wordpress/i18n';
import { PanelBody } from '@wordpress/components';
import Header from './settings/header';
import Main from './settings/main';
import General, { GeneralSettingsProps } from './settings/general';
import Checkout, { CheckoutSettingsProps } from './settings/checkout';
import Access from './settings/access';
import License from './settings/license';
import Footer from './settings/footer';
import Error from './error';
import { get } from 'lodash';

import './settings.scss';

interface AppProps {
	initialSettings: {
		general: GeneralSettingsProps;
		checkout: CheckoutSettingsProps;
	};
}

const App = ({ initialSettings }: AppProps) => {
	const { general, checkout } = initialSettings;
	const [open, setOpen] = React.useState('general');

	return (
		<ErrorBoundary
			FallbackComponent={Error}
			onReset={() => {
				console.log('reset');
			}}
		>
			<Header />
			<PanelBody
				title={__('General', 'woocommerce-pos')}
				// @ts-ignore
				onToggle={(o) => {
					o && setOpen('general');
				}}
				opened={open === 'general'}
			>
				<General initialSettings={general} />
			</PanelBody>
			<ErrorBoundary
				FallbackComponent={Error}
				onReset={() => {
					console.log('reset');
				}}
			>
				<PanelBody
					title={__('Checkout', 'woocommerce-pos')}
					// @ts-ignore
					onToggle={(o) => {
						if (o) {
							setOpen('checkout');
						}
					}}
					opened={open === 'checkout'}
				>
					<Checkout initialSettings={checkout} />
				</PanelBody>
			</ErrorBoundary>
			<PanelBody
				title="POS Access"
				// @ts-ignore
				onToggle={(o) => {
					if (o) {
						setOpen('access');
					}
				}}
				opened={open === 'access'}
			>
				<Access />
			</PanelBody>
			<PanelBody
				title="Pro License"
				// @ts-ignore
				onToggle={(o) => {
					if (o) {
						setOpen('license');
					}
				}}
				opened={open === 'license'}
			>
				<License />
			</PanelBody>
			<Footer />
		</ErrorBoundary>
	);
};

render(
	<App initialSettings={get(window, 'wcpos.settings')} />,
	document.getElementById('woocommerce-pos-settings')
);
