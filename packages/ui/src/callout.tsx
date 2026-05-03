import * as React from 'react';

import classNames from 'classnames';

export type CalloutStatus = 'info' | 'success' | 'warning' | 'error';

export interface CalloutProps {
	/**
	 * Visual status. Drives both the colour scheme and the default icon.
	 * Defaults to `info`.
	 */
	status?: CalloutStatus;
	/**
	 * Optional bold title rendered above the body.
	 */
	title?: React.ReactNode;
	/**
	 * Optional icon override. Falls back to a status-appropriate glyph.
	 * Pass `null` to render no icon.
	 */
	icon?: React.ReactNode;
	children: React.ReactNode;
	className?: string;
}

const STATUS_CLASSES: Record<CalloutStatus, string> = {
	info: 'wcpos:bg-blue-50 wcpos:border-blue-200 wcpos:text-blue-800 wcpos:border-l-blue-500',
	success:
		'wcpos:bg-green-50 wcpos:border-green-200 wcpos:text-green-800 wcpos:border-l-green-500',
	warning:
		'wcpos:bg-yellow-50 wcpos:border-yellow-200 wcpos:text-yellow-800 wcpos:border-l-yellow-500',
	error: 'wcpos:bg-red-50 wcpos:border-red-200 wcpos:text-red-800 wcpos:border-l-red-500',
};

const DEFAULT_ICONS: Record<CalloutStatus, string> = {
	info: 'i',
	success: '✓',
	warning: '!',
	error: '!',
};

/**
 * Persistent, non-dismissible inline explainer box.
 *
 * Use `Callout` for "what is this for?" context that should always stay
 * visible in a section. For transient, dismissible alerts after an action,
 * use `Notice` instead.
 */
export function Callout({
	status = 'info',
	title,
	icon,
	children,
	className,
}: CalloutProps) {
	const resolvedIcon = icon === undefined ? DEFAULT_ICONS[status] : icon;

	return (
		<div
			className={classNames(
				'wcpos:flex wcpos:items-start wcpos:gap-2.5 wcpos:rounded-md wcpos:border wcpos:border-l-4 wcpos:px-3 wcpos:py-2.5 wcpos:text-sm',
				STATUS_CLASSES[status],
				className
			)}
		>
			{resolvedIcon !== null && (
				<span
					className="wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:flex-shrink-0 wcpos:font-bold wcpos:leading-none wcpos:mt-0.5"
					aria-hidden="true"
				>
					{resolvedIcon}
				</span>
			)}
			<div className="wcpos:flex-1 wcpos:min-w-0">
				{title && <div className="wcpos:font-semibold wcpos:mb-0.5">{title}</div>}
				<div>{children}</div>
			</div>
		</div>
	);
}
