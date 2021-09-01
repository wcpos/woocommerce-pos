import * as React from 'react';
import classNames from 'classnames';

type TabProps = {
	name: string;
	title: string;
} & Record<string, any>;

interface TabsProps {
	tabs: TabProps[];
	children: (tab: TabProps) => React.ReactElement;
	orientation?: 'horizontal' | 'vertical';
}

const Tabs = ({ children, tabs, orientation }: TabsProps) => {
	const [selected, setSelected] = React.useState<number>(1);

	return (
		<>
			<div className="flex space-x-4 justify-center">
				{tabs.map((tab, index) => (
					<button
						key={tab.name}
						onClick={() => setSelected(index)}
						className={classNames(
							'text-sm px-4 py-2 border-b-4',
							selected === index ? 'border-wp-admin-theme-color' : 'border-transparent'
						)}
					>
						{tab.title}
					</button>
				))}
			</div>
			<div className="p-4">{children(tabs[selected])}</div>
		</>
	);
};

export default Tabs;
