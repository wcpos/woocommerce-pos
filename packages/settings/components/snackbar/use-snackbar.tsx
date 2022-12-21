import * as React from 'react';

import { SnackbarContext } from './provider';

/**
 * Get a function for showing a Snackbar with the specified options.
 *
 * Simply call the function to show the Snackbar, which will be automatically
 * dismissed.
 *
 * @example
 * const showSnackbar = useSnackbar({ message: 'This is a Snackbar!' })
 * <Button onClick={showSnackbar}>Show Snackbar!</Button>
 */
export const useSnackbar = () => {
	const context = React.useContext(SnackbarContext);

	if (!context) {
		throw new Error(`useSnackbar must be called within SnackbarProvider`);
	}

	return context;
};
