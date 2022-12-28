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
				'wcpos-inline-flex wcpos-justify-center wcpos-py-2 wcpos-px-4 wcpos-border wcpos-border-transparent wcpos-text-sm wcpos-font-medium wcpos-rounded-md focus:wcpos-outline-none',
				background == 'solid' &&
					'wcpos-text-white wcpos-bg-wp-admin-theme-color wcpos-shadow-sm hover:wcpos-bg-wp-admin-theme-color-darker-10 focus:wcpos-ring-2 focus:wcpos-ring-offset-2 focus:wcpos-ring-wp-admin-theme-color',
				background == 'outline' &&
					'wcpos-text-wp-admin-theme-color wcpos-border-wp-admin-theme-color hover:wcpos-border-wp-admin-theme-color-darker-10'
			)}
		>
			{children}
		</button>
	);
};

export default Button;
