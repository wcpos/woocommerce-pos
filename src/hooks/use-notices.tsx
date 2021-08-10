import * as React from 'react';

interface NoticeProps {
	type?: 'error' | 'info' | 'success';
	message: string;
}

interface SnackbarProps {
	id: string;
	content: React.ReactNode;
}

interface NoticesContext {
	notice: NoticeProps | null;
	snackbars: SnackbarProps[];
	setNotice: (args: NoticeProps | null) => void;
	setSnackbars: (args: SnackbarProps[]) => void;
}

const NoticesContext = React.createContext<NoticesContext>({
	notice: null,
	snackbars: [],
	// eslint-disable-next-line @typescript-eslint/no-empty-function
	setNotice: () => {},
	// eslint-disable-next-line @typescript-eslint/no-empty-function
	setSnackbars: () => {},
});

export const NoticesProvider: React.FC = ({ children }) => {
	const [notice, setNotice] = React.useState<NoticeProps | null>(null);
	const [snackbars, setSnackbars] = React.useState<SnackbarProps[]>([]);

	return (
		<NoticesContext.Provider value={{ notice, snackbars, setNotice, setSnackbars }}>
			{children}
		</NoticesContext.Provider>
	);
};

const useNotices = () => {
	return React.useContext(NoticesContext);
};

export default useNotices;
