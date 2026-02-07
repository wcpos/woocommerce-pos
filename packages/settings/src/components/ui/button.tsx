import * as React from 'react';

import classNames from 'classnames';

type ButtonVariant = 'primary' | 'secondary' | 'destructive';

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: ButtonVariant;
	loading?: boolean;
}

const variantClasses: Record<ButtonVariant, string> = {
	primary:
		'wcpos:bg-wp-admin-theme-color wcpos:text-white hover:wcpos:bg-wp-admin-theme-color-darker-10 focus:wcpos:ring-wp-admin-theme-color',
	secondary:
		'wcpos:bg-white wcpos:text-gray-700 wcpos:border wcpos:border-gray-300 hover:wcpos:bg-gray-50 focus:wcpos:ring-wp-admin-theme-color',
	destructive:
		'wcpos:bg-red-600 wcpos:text-white hover:wcpos:bg-red-700 focus:wcpos:ring-red-500',
};

export function Button({
	variant = 'secondary',
	loading = false,
	disabled,
	className,
	children,
	...props
}: ButtonProps) {
	return (
		<button
			disabled={disabled || loading}
			className={classNames(
				'wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:rounded-md wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:transition-colors wcpos:duration-150',
				'focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-offset-2',
				variantClasses[variant],
				(disabled || loading) && 'wcpos:opacity-50 wcpos:cursor-not-allowed',
				className
			)}
			{...props}
		>
			{loading && (
				<svg
					className="wcpos:mr-2 wcpos:h-4 wcpos:w-4 wcpos:animate-spin"
					xmlns="http://www.w3.org/2000/svg"
					fill="none"
					viewBox="0 0 24 24"
				>
					<circle
						className="wcpos:opacity-25"
						cx="12"
						cy="12"
						r="10"
						stroke="currentColor"
						strokeWidth="4"
					/>
					<path
						className="wcpos:opacity-75"
						fill="currentColor"
						d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
					/>
				</svg>
			)}
			{children}
		</button>
	);
}
