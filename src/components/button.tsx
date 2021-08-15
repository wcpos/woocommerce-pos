import * as React from 'react';

interface ButtonProps {
	children: string;
	disabled?: boolean;
	onClick?: React.MouseEventHandler<HTMLButtonElement>;
}

const Button = ({ children, disabled = false, onClick }: ButtonProps) => {
	return (
		<button
			disabled
			onClick={onClick}
			className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-wp-admin-theme-color hover:bg-wp-admin-theme-color-darker-10 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-wp-admin-theme-color"
		>
			{children}
		</button>
	);
};

export default Button;
