import React from 'react';
import ReactDOM from 'react-dom';
import { TabPanel } from '@wordpress/components';

const App = () => {
	const onSelect = (tabName: any) => {
		console.log('Selecting tab', tabName);
	};

	return (
		<>
			<TabPanel
				className="my-tab-panel"
				activeClass="active-tab"
				onSelect={onSelect}
				tabs={[
					{
						name: 'tab1',
						title: 'Tab 1',
						className: 'tab-one',
					},
					{
						name: 'tab2',
						title: 'Tab 2',
						className: 'tab-two',
					},
				]}
			>
				{(tab) => <p>{tab.title}</p>}
			</TabPanel>
		</>
	);
};

ReactDOM.render(<App />, document.getElementById('woocommerce-pos-settings'));
