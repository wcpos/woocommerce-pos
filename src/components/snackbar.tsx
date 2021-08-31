import * as React from 'react';
import { XIcon } from '@heroicons/react/solid';

interface SnackbarProps {
	message?: string;
	onRemove: () => void;
	timeout?: boolean;
}

const Snackbar = ({ message, onRemove, timeout }: SnackbarProps) => {
	React.useEffect(() => {
		const timer = setTimeout(() => {
			timeout && onRemove();
		}, 3000);
		return () => clearTimeout(timer);
	}, [message, onRemove, timeout]);

	return message ? (
		<div className="rounded-lg bg-wp-admin-theme-black shadow-lg text-white mb-2 p-4 pointer-events-auto flex">
			<span className="flex-1">{message}</span>
			<button>
				<XIcon onClick={onRemove} className="w-5 h-5 text-white" />
			</button>
		</div>
	) : null;
};

export default Snackbar;
