import { useSyncExternalStore } from 'react';

type Listener = () => void;

let count: number | null = (window as any)?.wcpos?.settings?.updateExtensionsCount ?? null;

const listeners = new Set<Listener>();

function emitChange() {
	for (const listener of listeners) {
		listener();
	}
}

function subscribe(listener: Listener) {
	listeners.add(listener);
	return () => listeners.delete(listener);
}

function getSnapshot() {
	return count;
}

/**
 * React hook that returns the number of extensions with updates available.
 * Returns null when the catalog hasn't been fetched yet.
 */
export function useUpdateExtensionsCount(): number | null {
	return useSyncExternalStore(subscribe, getSnapshot);
}

/**
 * Update the count externally (e.g. after fetching fresh extension data).
 */
export function setUpdateExtensionsCount(value: number) {
	count = value;
	emitChange();
}
