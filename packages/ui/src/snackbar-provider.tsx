import * as React from 'react';

import { Snackbar, SnackbarProps } from './snackbar';

export type AddSnackbarInput = Omit<SnackbarProps, 'id'> & { id?: string };

interface SnackbarContextValue {
	addSnackbar: (snackbar: AddSnackbarInput) => void;
}

export const SnackbarContext = React.createContext<SnackbarContextValue | null>(null);

interface SnackbarProviderProps {
	children: React.ReactNode;
}

let snackbarCounter = 0;

function generateSnackbarId(): string {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID();
	}
	snackbarCounter += 1;
	return `snackbar-${Date.now()}-${snackbarCounter}`;
}

/**
 * SnackbarProvider mounts a single snackbar slot fixed to the viewport, just
 * below the WP admin bar (32px desktop / 46px on screens ≤782px), so it stays
 * visible as the page scrolls.
 *
 * Only one snackbar is shown at a time — calling `addSnackbar` replaces any
 * currently-displayed snackbar. This matches the "saving → saved/error"
 * pattern used in the settings page.
 *
 * `id` is optional — if omitted, an id is generated automatically. Pass an
 * explicit id when you need to overwrite a specific in-flight snackbar (e.g.
 * replacing a "Saving…" message with "Saved" under the same id).
 */
export function SnackbarProvider({ children }: SnackbarProviderProps) {
	const [snackbars, setSnackbars] = React.useState<SnackbarProps[]>([]);

	const addSnackbar = React.useCallback((snackbar: AddSnackbarInput) => {
		const id = snackbar.id ?? generateSnackbarId();
		setSnackbars([{ ...snackbar, id }]);
	}, []);

	const removeSnackbar = React.useCallback((id: string) => {
		setSnackbars((prev) => prev.filter((snackbar) => snackbar.id !== id));
	}, []);

	return (
		<SnackbarContext.Provider value={{ addSnackbar }}>
			<div className="wcpos:relative wcpos:flex-1 wcpos:flex wcpos:flex-col">
				<div
					className="wcpos:fixed wcpos:top-[32px] wcpos:max-[783px]:top-[46px] wcpos:left-0 wcpos:right-0 wcpos:z-50 wcpos:overflow-hidden"
					role="status"
					aria-live="polite"
					aria-atomic="true"
				>
					{snackbars.map((snackbar) => (
						<Snackbar
							key={snackbar.id}
							{...snackbar}
							onRemove={() => {
								try {
									snackbar.onRemove?.();
								} finally {
									removeSnackbar(snackbar.id);
								}
							}}
						/>
					))}
				</div>
				{children}
			</div>
		</SnackbarContext.Provider>
	);
}

/**
 * Access the snackbar context. Must be called within a SnackbarProvider.
 */
export function useSnackbar() {
	const context = React.useContext(SnackbarContext);

	if (!context) {
		throw new Error('useSnackbar must be called within SnackbarProvider');
	}

	return context;
}
