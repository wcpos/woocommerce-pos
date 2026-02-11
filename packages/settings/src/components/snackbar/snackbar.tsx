import * as React from 'react';

import classNames from 'classnames';

export interface SnackbarProps {
	id: string;
	message: string;
	status?: 'saving' | 'success' | 'error';
	onRemove?: () => void;
	timeout?: boolean;
}

const statusClasses: Record<string, string> = {
	saving: 'wcpos:bg-gray-900 wcpos:text-white',
	success: 'wcpos:bg-green-600 wcpos:text-white',
	error: 'wcpos:bg-red-600 wcpos:text-white',
};

export function Snackbar({ message, status = 'saving', onRemove, timeout = true }: SnackbarProps) {
	const [visible, setVisible] = React.useState(false);

	// Slide in on mount
	React.useEffect(() => {
		if (!message) return;
		const frame = requestAnimationFrame(() => setVisible(true));
		return () => cancelAnimationFrame(frame);
	}, [message]);

	// Auto-dismiss: slide out then remove
	React.useEffect(() => {
		if (!message || status === 'saving' || !timeout) return;
		const timer = setTimeout(() => {
			setVisible(false);
			setTimeout(() => onRemove?.(), 300);
		}, 2000);
		return () => clearTimeout(timer);
	}, [message, status, onRemove, timeout]);

	if (!message) return null;

	return (
		<div
			className={classNames(
				'wcpos:w-full wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:text-center wcpos:transition-all wcpos:duration-300 wcpos:ease-out',
				statusClasses[status] || statusClasses.saving,
				visible
					? 'wcpos:translate-y-0 wcpos:opacity-100'
					: 'wcpos:-translate-y-full wcpos:opacity-0'
			)}
		>
			{message}
		</div>
	);
}
