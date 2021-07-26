import * as React from 'react';
import { TabPanel } from '@wordpress/components';

const Access = () => {
	return (
		<TabPanel
			className="my-tab-panel"
			activeClass="active-tab"
			orientation="vertical"
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
	);
};

export default Access;
