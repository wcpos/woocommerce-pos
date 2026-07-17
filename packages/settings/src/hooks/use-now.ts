import * as React from 'react';

/**
 * Wall-clock "now" (ms epoch) that keeps render pure.
 *
 * Reading `Date.now()` during render is impure (react-hooks/purity) and a
 * fetch-time anchor like react-query's `dataUpdatedAt` goes stale while cached
 * data is served (staleTime is 10 minutes here). This hook does both honestly:
 * the first render uses `initialMs` (pass `dataUpdatedAt`), then an immediate
 * post-mount timeout snaps to the real clock and an interval keeps it ticking.
 */
export function useNowMs(initialMs: number, intervalMs: number): number {
	// Deliberately not re-initialized when `initialMs` changes — the post-mount
	// timeout supersedes it with the real clock straight away.
	const [nowMs, setNowMs] = React.useState(initialMs);

	React.useEffect(() => {
		const update = () => setNowMs(Date.now());
		const timeoutId = window.setTimeout(update, 0);
		const intervalId = window.setInterval(update, intervalMs);
		return () => {
			window.clearTimeout(timeoutId);
			window.clearInterval(intervalId);
		};
	}, [intervalMs]);

	return nowMs;
}
