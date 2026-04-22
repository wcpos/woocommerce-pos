import type { LogEntryLike } from './group-by-day';

const MONTHS = [
	'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
	'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

function pad(n: number): string {
	return String(n).padStart(2, '0');
}

export function formatLocalTimestamp(iso: string): string {
	const d = new Date(iso);
	if (Number.isNaN(d.getTime())) return iso;
	const day = pad(d.getDate());
	const mon = MONTHS[d.getMonth()];
	const year = d.getFullYear();
	return `${day} ${mon} ${year}, ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

export function formatCopyPayload(entry: LogEntryLike): string {
	const header = `[${formatLocalTimestamp(entry.timestamp)}]  ${entry.level.toUpperCase()}`;
	const body = [header, entry.message];
	if (entry.context && entry.context.trim() !== '') {
		body.push('');
		body.push('Context:');
		body.push(entry.context);
	}
	return body.join('\n');
}
