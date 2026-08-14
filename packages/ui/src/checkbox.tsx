import * as React from 'react';

import classNames from 'classnames';

export interface CheckboxProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
	label?: string;
	/**
	 * Renders the native mixed state, e.g. a group toggle where only some of
	 * the members are selected. Ignored while `checked` is true.
	 */
	indeterminate?: boolean;
}

export function Checkbox({
	label,
	className,
	id,
	disabled,
	indeterminate,
	...props
}: CheckboxProps) {
	const generatedId = React.useId();
	const inputId = id || generatedId;
	const inputRef = React.useRef<HTMLInputElement>(null);

	/**
	 * `indeterminate` is a DOM property with no HTML attribute, so it has to be
	 * set imperatively after every render that can change it.
	 */
	React.useEffect(() => {
		if (inputRef.current) {
			inputRef.current.indeterminate = Boolean(indeterminate) && !props.checked;
		}
	}, [indeterminate, props.checked]);

	return (
		<div className={classNames('wcpos:flex wcpos:items-center wcpos:gap-2', className)}>
			<input
				ref={inputRef}
				id={inputId}
				type="checkbox"
				className={classNames(
					'wcpos:h-4 wcpos:w-4 wcpos:rounded wcpos:border-gray-300 wcpos:text-wp-admin-theme-color wcpos:focus:ring-wp-admin-theme-color',
					disabled ? 'wcpos:opacity-50 wcpos:cursor-not-allowed' : 'wcpos:cursor-pointer'
				)}
				disabled={disabled}
				{...props}
			/>
			{label && (
				<label
					htmlFor={inputId}
					className={classNames(
						'wcpos:text-sm wcpos:text-gray-700',
						disabled ? 'wcpos:opacity-50 wcpos:cursor-not-allowed' : 'wcpos:cursor-pointer'
					)}
				>
					{label}
				</label>
			)}
		</div>
	);
}
