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

export function groupByDay<T extends LogEntryLike>(
	entries: T[],
	nowMs: number,
	options: GroupByDayOptions = {}
): DayGroup<T>[] {
	if (entries.length === 0) return [];

	const { timeZone } = options;
	const todayISO = isoDateInZone(new Date(nowMs), timeZone);
	const yesterdayISO = isoDateInZone(new Date(nowMs - 24 * 60 * 60 * 1000), timeZone);

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
