import * as React from 'react';

import classNames from 'classnames';

export interface TextInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
	error?: boolean;
}

export const TextInput = React.forwardRef<HTMLInputElement, TextInputProps>(
	({ error, className, type = 'text', ...props }, ref) => {
		return (
			<input
				ref={ref}
				type={type}
				className={classNames(
					'wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs',
					'wcpos:transition-colors wcpos:duration-150',
					'wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-offset-0',
					error
						? 'wcpos:border-red-300 wcpos:focus:border-red-500 wcpos:focus:ring-red-500'
						: 'wcpos:border-gray-300 wcpos:focus:border-wp-admin-theme-color wcpos:focus:ring-wp-admin-theme-color',
					props.disabled &&
						'wcpos:bg-gray-50 wcpos:text-gray-500 wcpos:cursor-not-allowed',
					className
				)}
				{...props}
			/>
		);
	}
);

TextInput.displayName = 'TextInput';
