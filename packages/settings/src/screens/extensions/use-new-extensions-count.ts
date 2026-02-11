import { useSyncExternalStore } from 'react';

import apiFetch from '@wordpress/api-fetch';

type Listener = () => void;

let count: number | null = (window as any)?.wcpos?.settings?.newExtensionsCount ?? null;

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
 * React hook that returns the number of extensions the current user hasn't seen.
 * Returns null when the catalog hasn't been fetched yet.
 */
export function useNewExtensionsCount(): number | null {
	return useSyncExternalStore(subscribe, getSnapshot);
}

/**
 * Update the new extensions count (e.g. after computing from fetched data).
 */
export function setNewExtensionsCount(value: number) {
	count = value;
	emitChange();
}

/**
 * POST to the REST endpoint to mark all current catalog extensions as seen,
 * then reset the count to zero.
 */
export async function markExtensionsSeen() {
	count = 0;
	emitChange();

	await apiFetch({
		path: 'wcpos/v1/extensions/seen?wcpos=1',
		method: 'POST',
	});
}
