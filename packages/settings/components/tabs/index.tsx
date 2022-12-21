import * as React from 'react';

import { TabBar } from './tab-bar';

export type Route = {
	key: string;
	// icon?: string;
	title: string | ((props: { focused: boolean }) => React.ReactNode);
};

export type NavigationState<T extends Route> = {
	index: number;
	routes: T[];
};

export type TabsProps<T extends Route> = {
	onIndexChange: (index: number) => void;
	navigationState: NavigationState<T>;
	renderScene: (props: { route: T }) => React.ReactNode;
	// renderLazyPlaceholder?: (props: { route: T }) => React.ReactNode;
	// renderTabBar?: (props: { navigationState: NavigationState<T> }) => React.ReactNode;
	tabBarPosition?: 'top' | 'bottom' | 'left' | 'right';
};

const Tabs = <T extends Route>({
	onIndexChange,
	navigationState,
	renderScene,
	tabBarPosition = 'top',
}: TabsProps<T>) => {
	return (
		<>
			<TabBar
				routes={navigationState.routes}
				onIndexChange={onIndexChange}
				focusedIndex={navigationState.index}
			/>
			{renderScene({ route: navigationState.routes[navigationState.index] })}
		</>
	);
};

export default Tabs;
