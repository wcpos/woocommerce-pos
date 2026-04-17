import * as React from 'react';

import classNames from 'classnames';

export interface FilterTabItem {
	key: string;
	label: React.ReactNode;
	disabled?: boolean;
}

export interface FilterTabsProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'onChange'> {
	items: FilterTabItem[];
	value: string;
	onChange: (value: string) => void;
}

export function FilterTabs({ items, value, onChange, className, ...props }: FilterTabsProps) {
	return (
		<div className={classNames('wcpos:flex wcpos:gap-2 wcpos:flex-wrap', className)} {...props}>
			{items.map((item) => {
				const isActive = value === item.key;

				return (
					<button
						key={item.key}
						type="button"
						disabled={item.disabled}
						aria-pressed={isActive}
						onClick={() => onChange(item.key)}
						className={classNames(
							'wcpos:px-3 wcpos:py-1 wcpos:rounded-full wcpos:text-sm wcpos:font-medium wcpos:transition-colors',
							item.disabled
								? 'wcpos:cursor-not-allowed wcpos:opacity-50'
								: 'wcpos:cursor-pointer',
							isActive
								? 'wcpos:bg-wp-admin-theme-color wcpos:text-white'
								: 'wcpos:bg-gray-100 wcpos:text-gray-600 hover:wcpos:bg-gray-200'
						)}
					>
						{item.label}
					</button>
				);
			})}
		</div>
	);
}
