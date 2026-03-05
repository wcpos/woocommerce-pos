import * as React from 'react';
import classnames from 'classnames';

interface SnackbarMessage {
	id: string;
	message: string;
	status: 'success' | 'error' | 'info';
}

interface SnackbarContextValue {
	addSnackbar: (msg: Omit<SnackbarMessage, 'id'>) => void;
}

const SnackbarContext = React.createContext<SnackbarContextValue>({
	addSnackbar: () => {},
});

export function useSnackbar() {
	return React.useContext(SnackbarContext);
}

export function SnackbarProvider({ children }: { children: React.ReactNode }) {
	const [messages, setMessages] = React.useState<SnackbarMessage[]>([]);

	const addSnackbar = React.useCallback((msg: Omit<SnackbarMessage, 'id'>) => {
		const id = String(Date.now());
		setMessages((prev) => [...prev, { ...msg, id }]);

		setTimeout(() => {
			setMessages((prev) => prev.filter((m) => m.id !== id));
		}, 3000);
	}, []);

	return (
		<SnackbarContext.Provider value={{ addSnackbar }}>
			{children}
			{messages.length > 0 && (
				<div className="wcpos:fixed wcpos:bottom-4 wcpos:right-4 wcpos:z-50 wcpos:flex wcpos:flex-col wcpos:gap-2">
					{messages.map((msg) => (
						<div
							key={msg.id}
							className={classnames(
								'wcpos:px-4 wcpos:py-2 wcpos:rounded-md wcpos:shadow-lg wcpos:text-sm wcpos:text-white wcpos:animate-fade-in',
								msg.status === 'success' && 'wcpos:bg-green-600',
								msg.status === 'error' && 'wcpos:bg-red-600',
								msg.status === 'info' && 'wcpos:bg-gray-700',
							)}
						>
							{msg.message}
						</div>
					))}
				</div>
			)}
		</SnackbarContext.Provider>
	);
}
