import * as React from 'react';

import classNames from 'classnames';

export interface OptionProps extends Record<string, unknown> {
	value: string | number;
	label: string;
}

export interface SelectProps extends Omit<
	React.SelectHTMLAttributes<HTMLSelectElement>,
	'value' | 'onChange'
> {
	value: string | number;
	options: OptionProps[];
	onChange: (value: OptionProps) => void;
	placeholder?: string;
}

export function Select({
	value,
	options,
	onChange,
	placeholder,
	disabled,
	className,
	...props
}: SelectProps) {
	return (
		<select
			value={String(value)}
			onChange={(event) => {
				const selected = options.find(
					(option) => String(option.value) === event.target.value
				);
				if (selected) onChange(selected);
			}}
			disabled={disabled}
			className={classNames(
				'wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs',
				'wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:border-wp-admin-theme-color',
				disabled &&
					'wcpos:bg-gray-50 wcpos:text-gray-500 wcpos:cursor-not-allowed',
				className
			)}
			{...props}
		>
			{placeholder && (
				<option value="" disabled>
					{placeholder}
				</option>
			)}
			{options.map((option) => (
				<option key={String(option.value)} value={String(option.value)}>
					{option.label}
				</option>
			))}
		</select>
	);
}
