import * as React from 'react';
import classNames from 'classnames';

interface ButtonProps {
	children: string;
	disabled?: boolean;
	onClick?: React.MouseEventHandler<HTMLButtonElement>;
	background?: 'solid' | 'clear' | 'outline';
}

const Button = ({ children, disabled = false, onClick, background = 'solid' }: ButtonProps) => {
	return (
		<button
			disabled={disabled}
			onClick={onClick}
			className={classNames(
				'inline-flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md focus:outline-none',
				background == 'solid' &&
					'text-white bg-wp-admin-theme-color shadow-sm hover:bg-wp-admin-theme-color-darker-10 focus:ring-2 focus:ring-offset-2 focus:ring-wp-admin-theme-color',
				background == 'outline' &&
					'text-wp-admin-theme-color border-wp-admin-theme-color hover:border-wp-admin-theme-color-darker-10'
			)}
		>
			{children}
		</button>
	);
};

export default Button;
