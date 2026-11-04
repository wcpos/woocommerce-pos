import * as React from 'react';

import classNames from 'classnames';

export type ButtonVariant =
	| 'primary'
	| 'secondary'
	| 'destructive'
	| 'outline'
	| 'ghost'
	| 'ghost-destructive'
	| 'text'
	| /** @deprecated Use 'destructive' instead. */ 'danger';

type CanonicalButtonVariant = Exclude<ButtonVariant, 'danger'>;

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: ButtonVariant;
	loading?: boolean;
}

const variantClasses: Record<CanonicalButtonVariant, string> = {
	primary:
		'wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:hover:bg-wp-admin-theme-color-darker-10 wcpos:focus:ring-wp-admin-theme-color',
	secondary:
		'wcpos:bg-white wcpos:text-gray-700 wcpos:border wcpos:border-gray-300 wcpos:hover:bg-gray-50 wcpos:focus:ring-wp-admin-theme-color',
	destructive: 'wcpos:bg-red-600 wcpos:text-white wcpos:hover:bg-red-700 wcpos:focus:ring-red-500',
	outline:
		'wcpos:bg-transparent wcpos:text-wp-admin-theme-color wcpos:border wcpos:border-wp-admin-theme-color wcpos:hover:bg-wp-admin-theme-color-lightest wcpos:focus:ring-wp-admin-theme-color',
	ghost: 'wcpos:bg-transparent wcpos:text-gray-700 wcpos:hover:bg-gray-100 wcpos:focus:ring-wp-admin-theme-color',
	'ghost-destructive':
		'wcpos:bg-transparent wcpos:text-red-600 wcpos:hover:bg-red-50 wcpos:focus:ring-red-500',
	text: 'wcpos:bg-transparent wcpos:text-wp-admin-theme-color wcpos:hover:underline wcpos:focus:ring-wp-admin-theme-color',
};

function normalizeVariant(variant: ButtonVariant): CanonicalButtonVariant {
	return variant === 'danger' ? 'destructive' : variant;
}

function buttonClasses(variant: ButtonVariant, disabled: boolean, className?: string): string {
	const normalizedVariant = normalizeVariant(variant);
	const sizeClasses =
		normalizedVariant === 'text' ? 'wcpos:px-0 wcpos:py-0' : 'wcpos:px-3 wcpos:py-1.5';

	return classNames(
		'wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:rounded-md wcpos:text-sm wcpos:font-medium wcpos:transition-colors wcpos:duration-150',
		'wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-offset-2',
		sizeClasses,
		variantClasses[normalizedVariant],
		disabled ? 'wcpos:opacity-50 wcpos:cursor-not-allowed' : 'wcpos:cursor-pointer',
		className
	);
}

export function Button({
	variant = 'secondary',
	loading = false,
	disabled,
	className,
	children,
	type = 'button',
	...props
}: ButtonProps) {
	const isDisabled = disabled || loading;

	return (
		<button
			type={type}
			disabled={isDisabled}
			className={buttonClasses(variant, isDisabled, className)}
			{...props}
		>
			{loading && (
				<svg
					aria-hidden="true"
					focusable="false"
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

export interface LinkButtonProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
	variant?: ButtonVariant;
	href: string;
}

/**
 * An anchor styled exactly like `Button`, for navigation that should look like
 * an action (e.g. "Manage stores" linking to another admin screen). Navigation
 * only: there is no disabled state, use `Button` for an action that can be off.
 */
export function LinkButton({
	variant = 'secondary',
	className,
	children,
	...props
}: LinkButtonProps) {
	return (
		<a
			// wp-admin underlines every anchor; the button look has none.
			className={buttonClasses(variant, false, classNames('wcpos:no-underline', className))}
			{...props}
		>
			{children}
		</a>
	);
}
