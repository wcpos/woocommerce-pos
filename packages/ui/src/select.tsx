import * as React from 'react';

import classNames from 'classnames';

export interface OptionProps extends Record<string, unknown> {
	value: string | number;
	label: string;
	disabled?: boolean;
}

export interface SelectProps extends Omit<
	React.SelectHTMLAttributes<HTMLSelectElement>,
	'value' | 'onChange'
> {
	value: string | number;
	options: OptionProps[];
	onChange: (value: OptionProps) => void;
	placeholder?: string;
	/**
	 * Render the control sized to its content rather than filling its parent.
	 * The Tailwind layer is imported with `important`, so the default
	 * `wcpos:w-full` becomes `width: 100% !important` and would otherwise beat
	 * any `className`/`style` width a caller supplies. When `inline` is set we
	 * drop the width utility entirely so a caller's `style={{ width }}` wins —
	 * useful for the sentence-style auto-print rules.
	 */
	inline?: boolean;
}

export function Select({
	value,
	options,
	onChange,
	placeholder,
	disabled,
	className,
	inline,
	...props
}: SelectProps) {
	return (
		<select
			value={String(value)}
			onChange={(event) => {
				const selected = options.find((option) => String(option.value) === event.target.value);
				if (selected) onChange(selected);
			}}
			disabled={disabled}
			// pr-6 (24px): wp-admin's forms.css draws a 16px chevron at `right 8px`
			// and pads native selects `0 24px 0 12px`; less right padding lets long
			// option text run under the chevron.
			className={classNames(
				inline ? 'wcpos:inline-block' : 'wcpos:block wcpos:w-full',
				'wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:pl-2.5 wcpos:pr-6 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs',
				'wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:border-wp-admin-theme-color',
				disabled && 'wcpos:bg-gray-50 wcpos:text-gray-500 wcpos:cursor-not-allowed',
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
				<option
					key={String(option.value)}
					value={String(option.value)}
					disabled={Boolean(option.disabled)}
				>
					{option.label}
				</option>
			))}
		</select>
	);
}
