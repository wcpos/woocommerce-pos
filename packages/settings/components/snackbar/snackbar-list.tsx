import * as React from 'react';

import { Snackbar } from './snackbar';

export const SnackbarList = ({ snackbars, removeSnackbar }) => {
	return snackbars.map((snackbar) => (
		<Snackbar onRemove={() => removeSnackbar(snackbar.id)} key={snackbar.id} {...snackbar} />
	));
};
