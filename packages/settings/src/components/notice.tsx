import * as React from 'react';

import XMarkIcon from '@heroicons/react/24/solid/XMarkIcon';
import classNames from 'classnames';

interface NoticeProps {
	status?: 'error' | 'info' | 'success';
	onRemove?: () => void;
	children: React.ReactNode;
	isDismissible?: boolean;
}

const Notice = ({ status, children, onRemove, isDismissible = true }: NoticeProps) => {
	return (
		<div className="wcpos-p-4">
			<div
				className={classNames(
					'wcpos-flex wcpos-px-4 wcpos-py-2 wcpos-items-center',
					status === 'error' && 'wcpos-bg-red-300 wcpos-border-l-4 wcpos-border-red-600',
					status === 'info' && 'wcpos-bg-yellow-100 wcpos-border-l-4 wcpos-border-yellow-300',
					status === 'success' && 'wcpos-bg-green-100 wcpos-border-l-4 wcpos-border-green-600'
				)}
			>
				<div className="wcpos-flex-1">{children}</div>
				{isDismissible && <XMarkIcon onClick={onRemove} className="wcpos-h-5 wcpos-w-5" />}
			</div>
		</div>
	);
};

export default Notice;
