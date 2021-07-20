import * as React from 'react';
import { render } from '@wordpress/element';
import Header from './settings/header';
import Main from './settings/main';
import Footer from './settings/footer';

import './settings.scss';

const App = () => {
	const onSelect = (tabName: any) => {
		console.log('Selecting tab', tabName);
	};

	return (
		<>
			<Header />
			<Main />
			<Footer />
		</>
	);
};

render(<App />, document.getElementById('woocommerce-pos-settings'));
