import * as React from 'react';

import { SnackbarList } from './snackbar-list';

type Snackbar = import('./snackbar').SnackbarProps;

interface SnackbarContextProps {
	addSnackbar: (snackbar: Snackbar) => void;
	// removeSnackbar: (id: string) => void;
}

export const SnackbarContext = React.createContext<SnackbarContextProps>({
	addSnackbar: () => {},
	// removeSnackbar: () => {},
});

interface Props {
	children: React.ReactNode;
}

export function SnackbarProvider({ children }: Props) {
	const [snackbars, setSnackbars] = React.useState<Snackbar[]>([]);

	/**
	 * Note: snackbars is an array of objects, but for now we only support one snackbar at a time.
	 */
	const addSnackbar = (snackbar: Snackbar) => {
		// setSnackbars((prev) => [...prev, snackbar]);
		setSnackbars([snackbar]);
	};

	const removeSnackbar = (id: string) => {
		setSnackbars((prev) => prev.filter((snackbar) => snackbar.id !== id));
	};

	return (
		<SnackbarContext.Provider value={{ addSnackbar }}>
			{children}
			<div className="wcpos:fixed wcpos:bottom-8 wcpos:left-1/2 wcpos:-translate-x-1/2 wcpos:z-50 wcpos:pointer-events-none wcpos:flex wcpos:flex-col wcpos:items-center">
				<SnackbarList snackbars={snackbars} removeSnackbar={removeSnackbar} />
			</div>
		</SnackbarContext.Provider>
	);
}
