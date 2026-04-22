import type { LogEntryLike } from './group-by-day';

export interface FormatOptions {
	/** BCP 47 locale(s); defaults to the runtime's locale. */
	locale?: string | string[];
	/** IANA timezone (e.g. 'UTC', 'Australia/Sydney'); defaults to the runtime's local zone. */
	timeZone?: string;
}

/**
 * Format an ISO timestamp for the copy-paste support payload.
 *
 * Locale-aware: uses `Intl.DateTimeFormat` with the runtime's locale/timezone so
 * dates match what the user sees elsewhere in their admin. Falls back to the
 * raw input string when the timestamp is unparseable.
 */
export function formatLocalTimestamp(iso: string, options: FormatOptions = {}): string {
	const d = new Date(iso);
	if (Number.isNaN(d.getTime())) return iso;
	return new Intl.DateTimeFormat(options.locale, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		timeZone: options.timeZone,
	}).format(d);
}

export function formatCopyPayload(entry: LogEntryLike, options: FormatOptions = {}): string {
	const header = `[${formatLocalTimestamp(entry.timestamp, options)}]  ${entry.level.toUpperCase()}`;
	const body = [header, entry.message];
	if (entry.context && entry.context.trim() !== '') {
		body.push('');
		body.push('Context:');
		body.push(entry.context);
	}
	return body.join('\n');
}
