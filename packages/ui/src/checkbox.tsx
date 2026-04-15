import * as React from 'react';

import classNames from 'classnames';

export interface CheckboxProps extends Omit<
	React.InputHTMLAttributes<HTMLInputElement>,
	'type'
> {
	label?: string;
}

export function Checkbox({ label, className, id, ...props }: CheckboxProps) {
	const generatedId = React.useId();
	const inputId = id || generatedId;

	return (
		<div
			className={classNames(
				'wcpos:flex wcpos:items-center wcpos:gap-2',
				className
			)}
		>
			<input
				id={inputId}
				type="checkbox"
				className={classNames(
					'wcpos:h-4 wcpos:w-4 wcpos:rounded wcpos:border-gray-300 wcpos:text-wp-admin-theme-color wcpos:focus:ring-wp-admin-theme-color',
					props.disabled
						? 'wcpos:opacity-50 wcpos:cursor-not-allowed'
						: 'wcpos:cursor-pointer'
				)}
				{...props}
			/>
			{label && (
				<label
					htmlFor={inputId}
					className={classNames(
						'wcpos:text-sm wcpos:text-gray-700',
						props.disabled
							? 'wcpos:opacity-50 wcpos:cursor-not-allowed'
							: 'wcpos:cursor-pointer'
					)}
				>
					{label}
				</label>
			)}
		</div>
	);
}
