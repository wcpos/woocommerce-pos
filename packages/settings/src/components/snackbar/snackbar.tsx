import * as React from 'react';

export interface SnackbarProps {
	id: string;
	message: string;
	onRemove?: () => void;
	timeout?: boolean;
}

export function Snackbar({ message, onRemove, timeout = true }: SnackbarProps) {
	React.useEffect(() => {
		if (!message) return;
		const timer = setTimeout(() => {
			timeout && onRemove && onRemove();
		}, 3000);
		return () => clearTimeout(timer);
	}, [message, onRemove, timeout]);

	if (!message) return null;

	return (
		<div className="wcpos:bg-gray-800 wcpos:text-white wcpos:px-4 wcpos:py-2 wcpos:rounded-md wcpos:shadow-lg wcpos:text-sm wcpos:max-w-md wcpos:text-center">
			{message}
		</div>
	);
}
