import * as React from 'react';

import classNames from 'classnames';

export type ChipVariant =
	| 'neutral'
	| 'info'
	| 'success'
	| 'warning'
	| 'error'
	| 'critical'
	| 'debug'
	| 'brand';

export type ChipShape = 'pill' | 'round';
export type ChipSize = 'sm' | 'xs';

export interface ChipProps extends React.HTMLAttributes<HTMLSpanElement> {
	variant?: ChipVariant;
	shape?: ChipShape;
	size?: ChipSize;
	icon?: React.ReactNode;
}

const VARIANT_CLASSES: Record<ChipVariant, string> = {
	neutral: 'wcpos:bg-gray-100 wcpos:text-gray-700',
	info: 'wcpos:bg-blue-50 wcpos:text-blue-700',
	success: 'wcpos:bg-green-50 wcpos:text-green-700',
	warning: 'wcpos:bg-amber-50 wcpos:text-amber-700',
	error: 'wcpos:bg-red-50 wcpos:text-red-700',
	critical: 'wcpos:bg-red-100 wcpos:text-red-800',
	debug: 'wcpos:bg-gray-100 wcpos:text-gray-600',
	brand:
		'wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:uppercase wcpos:font-semibold wcpos:tracking-wide',
};

const SIZE_CLASSES: Record<ChipSize, string> = {
	sm: 'wcpos:text-xs wcpos:px-2 wcpos:py-0.5',
	xs: 'wcpos:text-[10px] wcpos:px-1.5 wcpos:py-0.5',
};

const SHAPE_CLASSES: Record<ChipShape, string> = {
	pill: 'wcpos:rounded-full',
	round: 'wcpos:rounded-full wcpos:min-w-5 wcpos:justify-center',
};

export function Chip({
	variant = 'neutral',
	shape = 'pill',
	size = 'sm',
	icon,
	className,
	children,
	...props
}: ChipProps) {
	return (
		<span
			className={classNames(
				'wcpos:inline-flex wcpos:items-center wcpos:gap-1 wcpos:font-medium wcpos:leading-none wcpos:shrink-0',
				VARIANT_CLASSES[variant],
				SIZE_CLASSES[size],
				SHAPE_CLASSES[shape],
				className
			)}
			{...props}
		>
			{icon}
			{children}
		</span>
	);
}
