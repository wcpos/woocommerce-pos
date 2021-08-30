import * as React from 'react';

interface NoticeProps {
	type?: 'error' | 'info' | 'success';
	message: string;
}

interface SnackbarProps {
	message: string;
}

interface NoticesContext {
	notice: NoticeProps | null;
	snackbar: SnackbarProps | null;
	setNotice: (args: NoticeProps | null) => void;
	setSnackbar: (args: SnackbarProps | null) => void;
}

const NoticesContext = React.createContext<NoticesContext>({
	notice: null,
	snackbar: null,
	// eslint-disable-next-line @typescript-eslint/no-empty-function
	setNotice: () => {},
	// eslint-disable-next-line @typescript-eslint/no-empty-function
	setSnackbar: () => {},
});

export const NoticesProvider: React.FC = ({ children }) => {
	const [notice, setNotice] = React.useState<NoticeProps | null>(null);
	const [snackbar, setSnackbar] = React.useState<SnackbarProps | null>(null);

	return (
		<NoticesContext.Provider value={{ notice, snackbar, setNotice, setSnackbar }}>
			{children}
		</NoticesContext.Provider>
	);
};

const useNotices = () => {
	return React.useContext(NoticesContext);
};

export default useNotices;
