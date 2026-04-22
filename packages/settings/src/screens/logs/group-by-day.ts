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

function isoDate(d: Date): string {
	const y = d.getUTCFullYear();
	const m = String(d.getUTCMonth() + 1).padStart(2, '0');
	const dd = String(d.getUTCDate()).padStart(2, '0');
	return `${y}-${m}-${dd}`;
}

export function groupByDay<T extends LogEntryLike>(entries: T[], nowMs: number): DayGroup<T>[] {
	if (entries.length === 0) return [];

	const now = new Date(nowMs);
	const todayISO = isoDate(now);

	const yesterdayDate = new Date(nowMs);
	yesterdayDate.setUTCDate(now.getUTCDate() - 1);
	const yesterdayISO = isoDate(yesterdayDate);

	const groups: DayGroup<T>[] = [];
	let current: DayGroup<T> | null = null;

	for (const entry of entries) {
		const parsed = new Date(entry.timestamp);
		let label: string;
		if (Number.isNaN(parsed.getTime())) {
			label = entry.timestamp;
		} else {
			const iso = isoDate(parsed);
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
