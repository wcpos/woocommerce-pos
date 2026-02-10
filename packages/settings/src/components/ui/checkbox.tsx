import * as React from 'react';

import classNames from 'classnames';

interface CheckboxProps extends Omit {
	label?: string;
}

export function Checkbox({ label, className, id, ...props }: CheckboxProps) {
	const generatedId = React.useId();
	const inputId = id || generatedId;

	return (
		<div className={classNames('wcpos:flex wcpos:items-center wcpos:gap-2', className)}>
			<input
				id={inputId}
				type="checkbox"
				className={classNames(
					'wcpos:h-4 wcpos:w-4 wcpos:rounded wcpos:border-gray-300 wcpos:cursor-pointer',
					'wcpos:text-wp-admin-theme-color focus:wcpos:ring-wp-admin-theme-color',
					props.disabled && 'wcpos:opacity-50 wcpos:cursor-not-allowed'
				)}
				{...props}
			/>
			{label && (
				<label
					htmlFor={inputId}
					className={classNames(
						'wcpos:text-sm wcpos:text-gray-700 wcpos:cursor-pointer',
						props.disabled && 'wcpos:opacity-50 wcpos:cursor-not-allowed'
					)}
				>
					{label}
				</label>
			)}
		</div>
	);
}
