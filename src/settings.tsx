import * as React from 'react';
import { render } from '@wordpress/element';
import { ErrorBoundary } from 'react-error-boundary';
import Header from './settings/header';
import Main from './settings/main';
import General, { GeneralSettingsProps } from './settings/general';
import Footer from './settings/footer';
import Error from './error';
import { get } from 'lodash';

import './settings.scss';

interface AppProps {
	initialSettings: {
		general: GeneralSettingsProps;
	};
}

const App = ({ initialSettings }: AppProps) => {
	const { general } = initialSettings;
	console.log(general);

	const onSelect = (tabName: any) => {
		console.log('Selecting tab', tabName);
	};

	return (
		<ErrorBoundary
			FallbackComponent={Error}
			onReset={() => {
				console.log('reset');
			}}
		>
			<Header />
			<General initialSettings={general} />
			<Main />
			<Footer />
		</ErrorBoundary>
	);
};

render(
	<App initialSettings={get(window, 'wcpos.settings')} />,
	document.getElementById('woocommerce-pos-settings')
);
