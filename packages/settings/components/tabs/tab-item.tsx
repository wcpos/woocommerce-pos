import * as React from 'react';

import classNames from 'classnames';

export interface TabItemProps {
	title: string | ((props: { focused: boolean }) => React.ReactNode);
	onClick: () => void;
	focused: boolean;
}

export const TabItem = ({ title, onClick, focused }: TabItemProps) => {
	return (
		<button
			onClick={onClick}
			className={classNames(
				'wcpos-text-sm wcpos-px-4 wcpos-py-2 wcpos-border-b-4',
				focused ? 'wcpos-border-wp-admin-theme-color' : 'wcpos-border-transparent'
			)}
		>
			{title}
		</button>
	);
};
