import * as React from 'react';
import { XIcon } from '@heroicons/react/solid';
import classNames from 'classnames';

interface NoticeProps {
	status?: 'error' | 'info' | 'success';
	onRemove?: () => void;
	children: React.ReactNode;
	isDismissible?: boolean;
}

const Notice = ({ status, children, onRemove, isDismissible = true }: NoticeProps) => {
	return (
		<div className="p-4">
			<div
				className={classNames(
					'flex px-4 py-2 items-center',
					status == 'error' && 'bg-red-300 border-l-4 border-red-600',
					status == 'info' && 'bg-yellow-100 border-l-4 border-yellow-300',
					status == 'success' && 'bg-green-100 border-l-4 border-green-600'
				)}
			>
				<p className="flex-1">{children}</p>
				{isDismissible && <XIcon onClick={onRemove} className="h-5 w-5" />}
			</div>
		</div>
	);
};

export default Notice;
