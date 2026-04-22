export interface LogEntryLike {
	timestamp: string;
	level: string;
	message: string;
	context: string;
	source?: string;
}

export interface DayGroup<T extends LogEntryLike> {
	/** 'today' | 'yesterday' | ISO date (`YYYY-MM-DD`) | raw timestamp when unparseable */
	label: string;
	entries: T[];
}

export interface GroupByDayOptions {
	/** IANA timezone (e.g. 'UTC', 'Australia/Sydney'). Defaults to the runtime's local zone. */
	timeZone?: string;
}

/**
 * Returns a `YYYY-MM-DD` date key for the given Date in the requested timezone.
 * Uses `Intl.DateTimeFormat('en-CA')` which natively emits that shape.
 */
function isoDateInZone(d: Date, timeZone?: string): string {
	const fmt = new Intl.DateTimeFormat('en-CA', {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		timeZone,
	});
	return fmt.format(d);
}

/**
 * Returns the calendar day before the given `YYYY-MM-DD` key. Anchors at UTC
 * midnight and steps back one day so DST transitions in the target zone can't
 * shift the result by ±1 calendar day.
 */
function previousIsoDate(iso: string): string {
	const [y, m, d] = iso.split('-').map(Number);
	const prev = new Date(Date.UTC(y, m - 1, d) - 24 * 60 * 60 * 1000);
	return isoDateInZone(prev, 'UTC');
}

/**
 * Group consecutive entries sharing the same local-day key. Assumes the input
 * is already sorted by timestamp (the REST endpoint returns DESC); unsorted
 * input would produce duplicate groups for the same day rather than one.
 */
export function groupByDay<T extends LogEntryLike>(
	entries: T[],
	nowMs: number,
	options: GroupByDayOptions = {}
): DayGroup<T>[] {
	if (entries.length === 0) return [];

	const { timeZone } = options;
	const todayISO = isoDateInZone(new Date(nowMs), timeZone);
	const yesterdayISO = previousIsoDate(todayISO);

	const groups: DayGroup<T>[] = [];
	let current: DayGroup<T> | null = null;

	for (const entry of entries) {
		const parsed = new Date(entry.timestamp);
		let label: string;
		if (Number.isNaN(parsed.getTime())) {
			label = entry.timestamp;
		} else {
			const iso = isoDateInZone(parsed, timeZone);
			if (iso === todayISO) label = 'today';
			else if (iso === yesterdayISO) label = 'yesterday';
			else label = iso;
		}

		if (!current || current.label !== label) {
			current = { label, entries: [] };
			groups.push(current);
		}
		current.entries.push(entry);
	}

	return groups;
}
