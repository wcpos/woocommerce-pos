import * as React from 'react';

import { TabItem } from './tab-item';

type Route = import('./').Route;

export interface TabBarProps {
	routes: Route[];
	onIndexChange: (index: number) => void;
	onTabItemHover?: (index: number, route: Route) => void;
	direction?: 'horizontal' | 'vertical';
	focusedIndex: number;
}

export const TabBar = ({
	routes,
	onIndexChange,
	onTabItemHover,
	direction = 'horizontal',
	focusedIndex,
}: TabBarProps) => {
	return (
		<div className="wcpos:flex wcpos:space-x-4 wcpos:justify-center">
			{routes.map((route, i) => {
				const focused = i === focusedIndex;
				return (
					<TabItem
						key={route.key}
						title={route.title}
						onClick={() => onIndexChange(i)}
						onHover={() => onTabItemHover && onTabItemHover(i, route)}
						focused={focused}
					/>
				);
			})}
		</div>
	);
};
