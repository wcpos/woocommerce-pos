import * as React from 'react';

import { Snackbar as WPSnackbar } from '@wordpress/components';

export interface SnackbarProps {
	id: string;
	message: string;
	onRemove?: () => void;
	timeout?: boolean;
}

export const Snackbar = ({ message, onRemove, timeout = true }: SnackbarProps) => {
	React.useEffect(() => {
		const timer = setTimeout(() => {
			timeout && onRemove && onRemove();
		}, 3000);
		return () => clearTimeout(timer);
	}, [message, onRemove, timeout]);

	return message ? <WPSnackbar>{message}</WPSnackbar> : null;
};
