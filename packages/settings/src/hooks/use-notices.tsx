import * as React from 'react';

interface NoticeProps {
	type?: 'error' | 'info' | 'warning' | 'success';
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

interface NoticesProviderProps {
	children: React.ReactNode;
}

export function NoticesProvider({ children }: NoticesProviderProps) {
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
}

const useNotices = () => {
	return React.useContext(NoticesContext);
};

export default useNotices;
