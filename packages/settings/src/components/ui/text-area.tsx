import * as React from 'react';

import classNames from 'classnames';

interface TextAreaProps extends React.TextareaHTMLAttributes {
	error?: boolean;
}

export const TextArea = React.forwardRef<HTMLTextAreaElement, TextAreaProps>(
	({ error, className, ...props }, ref) => {
		return (
			<textarea
				ref={ref}
				className={classNames(
					'wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs',
					'wcpos:transition-colors wcpos:duration-150',
					'focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-offset-0',
					error
						? 'wcpos:border-red-300 focus:wcpos:border-red-500 focus:wcpos:ring-red-500'
						: 'wcpos:border-gray-300 focus:wcpos:border-wp-admin-theme-color focus:wcpos:ring-wp-admin-theme-color',
					props.disabled && 'wcpos:bg-gray-50 wcpos:text-gray-500 wcpos:cursor-not-allowed',
					className
				)}
				{...props}
			/>
		);
	}
);

TextArea.displayName = 'TextArea';
