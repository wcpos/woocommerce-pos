import * as React from 'react';

import snackbar from '.';
import { Snackbar, SnackbarProps } from './snackbar';

export interface SnackbarListProps {
	snackbars: SnackbarProps[];
	removeSnackbar: (id: string) => void;
}

export const SnackbarList = ({ snackbars, removeSnackbar }: SnackbarListProps) => {
	return snackbars.map((snackbar) => (
		<Snackbar onRemove={() => removeSnackbar(snackbar.id)} key={snackbar.id} {...snackbar} />
	));
};
