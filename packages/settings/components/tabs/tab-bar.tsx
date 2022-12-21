import * as React from 'react';

import { TabItem } from './tab-item';

export interface TabBarProps {
	routes: import('./').Route[];
	onIndexChange: (index: number) => void;
	direction?: 'horizontal' | 'vertical';
	focusedIndex: number;
}

export const TabBar = ({
	routes,
	onIndexChange,
	direction = 'horizontal',
	focusedIndex,
}: TabBarProps) => {
	return (
		<div className="wcpos-flex wcpos-space-x-4 wcpos-justify-center">
			{routes.map((route, i) => {
				const focused = i === focusedIndex;
				return (
					<TabItem
						key={route.key}
						title={route.title}
						onClick={() => onIndexChange(i)}
						focused={focused}
					/>
				);
			})}
		</div>
	);
};
