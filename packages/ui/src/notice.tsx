import * as React from 'react';

import classNames from 'classnames';

export type NoticeStatus = 'info' | 'warning' | 'error' | 'success';

export interface NoticeProps {
	status?: NoticeStatus;
	onRemove?: () => void;
	children: React.ReactNode;
	isDismissible?: boolean;
	className?: string;
	/**
	 * Accessible label for the dismiss button. Consumers should pass a
	 * translated string. Defaults to "Dismiss" if not provided.
	 */
	dismissLabel?: string;
}

const statusClasses: Record<NoticeStatus, string> = {
	info: 'wcpos:bg-blue-50 wcpos:border-blue-200 wcpos:text-blue-800 wcpos:border-l-blue-500',
	warning:
		'wcpos:bg-yellow-50 wcpos:border-yellow-200 wcpos:text-yellow-800 wcpos:border-l-yellow-500',
	error: 'wcpos:bg-red-50 wcpos:border-red-200 wcpos:text-red-800 wcpos:border-l-red-500',
	success: 'wcpos:bg-green-50 wcpos:border-green-200 wcpos:text-green-800 wcpos:border-l-green-500',
};

function CloseIcon() {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 20 20"
			fill="currentColor"
			className="wcpos:h-4 wcpos:w-4"
			aria-hidden="true"
		>
			<path
				fillRule="evenodd"
				d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
				clipRule="evenodd"
			/>
		</svg>
	);
}

export function Notice({
	status = 'info',
	children,
	onRemove,
	isDismissible = true,
	className,
	dismissLabel = 'Dismiss',
}: NoticeProps) {
	return (
		<div
			className={classNames(
				'wcpos:flex wcpos:items-start wcpos:gap-2 wcpos:rounded-md wcpos:border wcpos:border-l-4 wcpos:px-3 wcpos:py-2.5 wcpos:text-sm',
				statusClasses[status],
				className
			)}
		>
			<div className="wcpos:flex-1">{children}</div>
			{isDismissible && onRemove && (
				<button
					type="button"
					aria-label={dismissLabel}
					onClick={onRemove}
					className="wcpos:shrink-0 wcpos:bg-transparent wcpos:border-0 wcpos:cursor-pointer wcpos:p-0.5 wcpos:rounded hover:wcpos:bg-black/5"
				>
					<CloseIcon />
				</button>
			)}
		</div>
	);
}
