import * as React from 'react';

interface NoticeProps {
	type?: 'error' | 'info' | 'success';
	message: string;
}

interface NoticesContextProps {
	notice: NoticeProps | null;
	setNotice: (args: NoticeProps | null) => void;
}

const NoticesContext = React.createContext<NoticesContextProps>({
	notice: null,
	setNotice: () => {},
});

export const NoticesProvider = ({ children }) => {
	const [notice, setNotice] = React.useState<NoticeProps | null>(null);

	return (
		<NoticesContext.Provider
			value={{
				notice,
				setNotice,
			}}
		>
			{children}
		</NoticesContext.Provider>
	);
};

const useNotices = () => {
	return React.useContext(NoticesContext);
};

export default useNotices;
