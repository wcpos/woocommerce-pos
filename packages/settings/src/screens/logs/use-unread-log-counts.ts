import { useSyncExternalStore } from 'react';

import apiFetch from '@wordpress/api-fetch';

type Listener = () => void;

interface UnreadLogCounts {
	error: number;
	warning: number;
}

let counts: UnreadLogCounts = (window as any)?.wcpos?.settings?.unreadLogCounts ?? {
	error: 0,
	warning: 0,
};

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

function getSnapshot(): UnreadLogCounts {
	return counts;
}

/**
 * React hook that returns unread error/warning counts since last viewed.
 */
export function useUnreadLogCounts(): UnreadLogCounts {
	return useSyncExternalStore(subscribe, getSnapshot);
}

/**
 * POST to the REST endpoint to mark logs as read, then reset counts to zero.
 */
export async function markLogsRead() {
	counts = { error: 0, warning: 0 };
	emitChange();

	await apiFetch({
		path: 'wcpos/v1/logs/mark-read?wcpos=1',
		method: 'POST',
	});
}
