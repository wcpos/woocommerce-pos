import * as React from 'react';

import { SnackbarList } from './snackbar-list';

type Snackbar = import('./snackbar').SnackbarProps;

interface SnackbarContextProps {
	addSnackbar: (snackbar: Snackbar) => void;
}

export const SnackbarContext = React.createContext<SnackbarContextProps>({
	addSnackbar: () => {},
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
		setSnackbars([snackbar]);
	};

	const removeSnackbar = (id: string) => {
		setSnackbars((prev) => prev.filter((snackbar) => snackbar.id !== id));
	};

	return (
		<SnackbarContext.Provider value={{ addSnackbar }}>
			<div className="wcpos:relative wcpos:flex-1 wcpos:flex wcpos:flex-col">
				<div className="wcpos:absolute wcpos:top-0 wcpos:left-0 wcpos:right-0 wcpos:z-50 wcpos:overflow-hidden">
					<SnackbarList snackbars={snackbars} removeSnackbar={removeSnackbar} />
				</div>
				{children}
			</div>
		</SnackbarContext.Provider>
	);
}
