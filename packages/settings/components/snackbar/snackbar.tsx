import * as React from 'react';

import XMarkIcon from '@heroicons/react/24/solid/XMarkIcon';

interface SnackbarProps {
	message?: string;
	onRemove: () => void;
	timeout?: boolean;
}

export const Snackbar = ({ message, onRemove, timeout = true }: SnackbarProps) => {
	React.useEffect(() => {
		const timer = setTimeout(() => {
			timeout && onRemove();
		}, 3000);
		return () => clearTimeout(timer);
	}, [message, onRemove, timeout]);

	return message ? (
		<div className="wcpos-rounded-lg wcpos-bg-wp-admin-theme-black wcpos-shadow-lg wcpos-text-white wcpos-mb-2 wcpos-p-4 wcpos-pointer-events-auto wcpos-flex">
			<span className="wcpos-flex-1">{message}</span>
			<button>
				<XMarkIcon onClick={onRemove} className="wcpos-w-5 wcpos-h-5 wcpos-text-white" />
			</button>
		</div>
	) : null;
};
