import * as React from 'react';

import { SnackbarList } from './snackbar-list';

export const SnackbarContext = React.createContext(null);

export const SnackbarProvider = ({ children }) => {
	const [snackbars, setSnackbars] = React.useState([]);

	const addSnackbar = (snackbar) => {
		setSnackbars((prev) => [...prev, snackbar]);
	};

	const removeSnackbar = (id) => {
		setSnackbars((prev) => prev.filter((snackbar) => snackbar.id !== id));
	};

	return (
		<SnackbarContext.Provider value={{ addSnackbar }}>
			{children}
			<div className="wcpos-fixed wcpos-w-48 wcpos-h-48 wcpos-bottom-8 wcpos-pointer-events-none wcpos-flex wcpos-flex-col wcpos-justify-end">
				<SnackbarList snackbars={snackbars} removeSnackbar={removeSnackbar} />
			</div>
		</SnackbarContext.Provider>
	);
};
