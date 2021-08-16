import * as React from 'react';
import { XIcon } from '@heroicons/react/solid';

interface NoticeProps {
	status?: 'error' | 'info' | 'success';
	onRemove?: () => void;
	children: React.ReactNode;
	isDismissible?: boolean;
}

const Notice = ({ status, children, onRemove, isDismissible = true }: NoticeProps) => {
	return (
		<div className="p-4">
			<div className="flex px-4 py-2 bg-red-300 border-l-4 border-red-600 items-center">
				<p className="flex-1">{children}</p>
				{isDismissible && <XIcon onClick={onRemove} className="h-5 w-5" />}
			</div>
		</div>
	);
};

export default Notice;
