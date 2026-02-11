import * as React from 'react';

import classNames from 'classnames';

import CloseIcon from '../../assets/close-icon.svg';
import { t } from '../translations';

type NoticeStatus = 'info' | 'warning' | 'error' | 'success';

interface NoticeProps {
	status?: NoticeStatus;
	onRemove?: () => void;
	children: React.ReactNode;
	isDismissible?: boolean;
	className?: string;
}

const statusClasses: Record<NoticeStatus, string> = {
	info: 'wcpos:bg-blue-50 wcpos:border-blue-200 wcpos:text-blue-800 wcpos:border-l-blue-500',
	warning:
		'wcpos:bg-yellow-50 wcpos:border-yellow-200 wcpos:text-yellow-800 wcpos:border-l-yellow-500',
	error: 'wcpos:bg-red-50 wcpos:border-red-200 wcpos:text-red-800 wcpos:border-l-red-500',
	success: 'wcpos:bg-green-50 wcpos:border-green-200 wcpos:text-green-800 wcpos:border-l-green-500',
};

function Notice({
	status = 'info',
	children,
	onRemove,
	isDismissible = true,
	className,
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
			{isDismissible && (
				<button
					type="button"
					aria-label={t('common.dismiss')}
					onClick={onRemove}
					className="wcpos:shrink-0 wcpos:bg-transparent wcpos:border-0 wcpos:cursor-pointer wcpos:p-0.5 wcpos:rounded hover:wcpos:bg-black/5"
				>
					<CloseIcon className="wcpos:h-4 wcpos:w-4" fill="currentColor" />
				</button>
			)}
		</div>
	);
}

export default Notice;
