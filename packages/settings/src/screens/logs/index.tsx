import * as React from 'react';

import { useSuspenseQuery } from '@tanstack/react-query';
import { Button, Chip, FilterTabs, TextArea, type ChipVariant } from '@wcpos/ui';
import apiFetch from '@wordpress/api-fetch';

import { formatCopyPayload, formatLocalTimestamp } from './format-copy-payload';
import { groupByDay } from './group-by-day';
import { markLogsRead } from './use-unread-log-counts';
import Notice from '../../components/notice';
import { Select } from '../../components/ui';
import { t } from '../../translations';

const ALL_SOURCES = 'all';

interface LogEntry {
	timestamp: string;
	level: string;
	message: string;
	context: string;
	source?: string;
}

interface LogSource {
	source: string;
	name: string;
	requires_pro: boolean;
	is_core: boolean;
}

interface LogsResponse {
	entries: LogEntry[];
	has_fatal_errors: boolean;
	fatal_errors_url: string;
	sources?: LogSource[];
	/** Injected client-side from the `X-WP-TotalPages` response header. */
	_totalPages?: number;
}

/** Stripe and message-tint colours per level. */
const LEVEL_STRIPE: Record<string, string> = {
	emergency: 'wcpos:bg-red-600',
	alert: 'wcpos:bg-red-600',
	critical: 'wcpos:bg-red-500',
	error: 'wcpos:bg-red-400',
	warning: 'wcpos:bg-amber-400',
	notice: 'wcpos:bg-blue-400',
	info: 'wcpos:bg-blue-400',
	debug: 'wcpos:bg-gray-400',
};

const LEVEL_TEXT: Record<string, string> = {
	emergency: 'wcpos:text-red-800',
	alert: 'wcpos:text-red-800',
	critical: 'wcpos:text-red-700',
	error: 'wcpos:text-red-700',
	warning: 'wcpos:text-amber-700',
	notice: 'wcpos:text-blue-700',
	info: 'wcpos:text-blue-700',
	debug: 'wcpos:text-gray-700',
};

const LEVEL_HOVER: Record<string, string> = {
	emergency: 'hover:wcpos:bg-red-50',
	alert: 'hover:wcpos:bg-red-50',
	critical: 'hover:wcpos:bg-red-50',
	error: 'hover:wcpos:bg-red-50',
	warning: 'hover:wcpos:bg-amber-50',
	notice: 'hover:wcpos:bg-blue-50',
	info: 'hover:wcpos:bg-blue-50',
	debug: 'hover:wcpos:bg-gray-50',
};

const LEVEL_CHIP: Record<string, ChipVariant> = {
	emergency: 'critical',
	alert: 'critical',
	critical: 'critical',
	error: 'error',
	warning: 'warning',
	notice: 'info',
	info: 'info',
	debug: 'debug',
};

function levelKey(level: string): string {
	return LEVEL_STRIPE[level] ? level : 'debug';
}

/**
 * Format the row's time column. Locale/timezone-aware via `toLocaleTimeString`
 * with `hourCycle: 'h23'` forced so the monospace column stays aligned across
 * 12-hour locales (en-US would otherwise emit " 2:58:01 PM").
 */
function formatRowTime(timestamp: string): string {
	const d = new Date(timestamp);
	if (Number.isNaN(d.getTime())) return timestamp;
	return d.toLocaleTimeString(undefined, {
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		hourCycle: 'h23',
	});
}

function dayHeaderLabel(label: string): string {
	if (label === 'today') return t('logs.today', 'Today');
	if (label === 'yesterday') return t('logs.yesterday', 'Yesterday');
	return label;
}

/**
 * Copy text to the clipboard, falling back to a hidden-textarea +
 * `document.execCommand('copy')` when the async Clipboard API is unavailable
 * (HTTP / non-secure WP admin) or rejected by the browser.
 */
async function copyText(text: string): Promise<boolean> {
	if (typeof navigator !== 'undefined' && navigator.clipboard && window.isSecureContext) {
		try {
			await navigator.clipboard.writeText(text);
			return true;
		} catch {
			// fall through to legacy fallback
		}
	}

	if (typeof document === 'undefined') return false;

	const textarea = document.createElement('textarea');
	textarea.value = text;
	textarea.setAttribute('readonly', '');
	textarea.style.position = 'fixed';
	textarea.style.left = '-9999px';
	textarea.style.top = '0';
	document.body.appendChild(textarea);
	const previousActive = document.activeElement as HTMLElement | null;
	textarea.focus();
	textarea.select();
	let ok = false;
	try {
		ok = document.execCommand('copy');
	} catch {
		ok = false;
	}
	document.body.removeChild(textarea);
	previousActive?.focus?.();
	return ok;
}

function CopyButton({ payload }: { payload: string }) {
	const [status, setStatus] = React.useState<'idle' | 'copied' | 'failed'>('idle');

	const handleCopy = async () => {
		const ok = await copyText(payload);
		setStatus(ok ? 'copied' : 'failed');
		setTimeout(() => setStatus('idle'), 1500);
	};

	const label =
		status === 'copied'
			? t('logs.copied', 'Copied')
			: status === 'failed'
				? t('logs.copy_failed', 'Copy failed')
				: t('logs.copy', 'Copy');

	return (
		<Button variant="secondary" onClick={handleCopy}>
			{label}
		</Button>
	);
}

function LogRow({
	entry,
	isExpanded,
	onToggle,
	sourceName,
}: {
	entry: LogEntry;
	isExpanded: boolean;
	onToggle: () => void;
	sourceName: string;
}) {
	const key = levelKey(entry.level);
	const payload = React.useMemo(() => formatCopyPayload(entry), [entry]);

	return (
		<div className="wcpos:border-b wcpos:border-gray-100 wcpos:last:border-b-0">
			<button
				type="button"
				aria-expanded={isExpanded}
				onClick={onToggle}
				className={`wcpos:grid wcpos:grid-cols-[78px_1fr_14px] wcpos:items-center wcpos:gap-3 wcpos:w-full wcpos:text-left wcpos:bg-transparent wcpos:border-0 wcpos:py-2 wcpos:pr-2 wcpos:pl-4 wcpos:cursor-pointer wcpos:relative ${LEVEL_HOVER[key]}`}
			>
				<span
					aria-hidden="true"
					className={`wcpos:absolute wcpos:left-0 wcpos:top-0 wcpos:bottom-0 wcpos:w-1 ${LEVEL_STRIPE[key]}`}
				/>
				<span className="wcpos:text-xs wcpos:font-mono wcpos:text-gray-500">
					{formatRowTime(entry.timestamp)}
				</span>
				<span className={`wcpos:text-sm wcpos:break-words ${LEVEL_TEXT[key]}`}>
					{entry.message}
				</span>
				<span
					aria-hidden="true"
					className={`wcpos:text-gray-400 wcpos:transition-transform ${isExpanded ? 'wcpos:rotate-90' : ''}`}
				>
					›
				</span>
			</button>
			{isExpanded && (
				<div className="wcpos:px-4 wcpos:pb-3">
					<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:bg-gray-50 wcpos:rounded-t-md wcpos:px-3 wcpos:py-2 wcpos:gap-2">
						<div className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:flex-wrap">
							<Chip variant={LEVEL_CHIP[key]}>{entry.level.toUpperCase()}</Chip>
							<Chip variant="neutral">{sourceName}</Chip>
							<Chip variant="neutral">{formatLocalTimestamp(entry.timestamp)}</Chip>
						</div>
						<CopyButton payload={payload} />
					</div>
					<TextArea
						readOnly
						value={payload}
						onFocus={(e) => e.currentTarget.select()}
						className="wcpos:bg-gray-900 wcpos:text-gray-100 wcpos:font-mono wcpos:text-xs wcpos:rounded-b-md wcpos:rounded-t-none wcpos:border-gray-900 wcpos:min-h-[160px]"
					/>
				</div>
			)}
		</div>
	);
}

function Logs() {
	const [filter, setFilter] = React.useState<string>('all');
	const [source, setSource] = React.useState<string>(ALL_SOURCES);
	const [expandedKey, setExpandedKey] = React.useState<string | null>(null);
	const [page, setPage] = React.useState(1);

	const levelParam = filter === 'all' ? '' : `&level=${filter}`;
	const sourceParam = `&source=${encodeURIComponent(source)}`;

	const { data } = useSuspenseQuery<LogsResponse>({
		queryKey: ['logs', filter, source, page],
		queryFn: () =>
			apiFetch({
				path: `wcpos/v1/logs?wcpos=1&per_page=50&page=${page}${levelParam}${sourceParam}`,
				method: 'GET',
				parse: false,
			}).then(async (response: any) => {
				const json = await response.json();
				return {
					...json,
					_totalPages: parseInt(response.headers.get('X-WP-TotalPages') || '1', 10),
				};
			}),
	});

	const entries = data?.entries ?? [];
	const availableSources = data?.sources ?? [];
	const sourceLookup = React.useMemo(() => {
		const map = new Map<string, LogSource>();
		availableSources.forEach((s) => map.set(s.source, s));
		return map;
	}, [availableSources]);
	const totalPages = data?._totalPages ?? 1;

	React.useEffect(() => {
		markLogsRead();
	}, []);

	React.useEffect(() => {
		setExpandedKey(null);
	}, [entries]);

	// `Date.now()` pinned to the entries reference — day boundaries only matter
	// when a new fetch arrives. Recomputing on every render would defeat the memo.
	const groups = React.useMemo(() => groupByDay(entries, Date.now()), [entries]);

	const filters = [
		{ key: 'all', label: t('common.all', 'All') },
		{ key: 'error', label: t('logs.errors', 'Errors') },
		{ key: 'warning', label: t('logs.warnings', 'Warnings') },
	];

	return (
		<div>
			{data?.has_fatal_errors && (
				<Notice status="warning" isDismissible={false} className="wcpos:mb-4">
					{t('logs.fatal_errors_detected', 'Fatal errors detected')}{' — '}
					<a href={data.fatal_errors_url} target="_blank" rel="noopener noreferrer">
						{t('logs.view_in_wc', 'view in WooCommerce logs')}
					</a>
				</Notice>
			)}

			<div className="wcpos:mb-4 wcpos:flex wcpos:flex-wrap wcpos:items-center wcpos:gap-3">
				<FilterTabs
					items={filters}
					value={filter}
					onChange={(next) => {
						setFilter(next);
						setPage(1);
						setExpandedKey(null);
					}}
				/>
				{availableSources.length > 1 && (
					<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
						<label
							htmlFor="wcpos-log-source-select"
							className="wcpos:text-sm wcpos:text-gray-600"
						>
							{t('logs.source', 'Source')}
						</label>
						<Select
							id="wcpos-log-source-select"
							value={source}
							onChange={(option) => {
								setSource(String(option.value));
								setPage(1);
								setExpandedKey(null);
							}}
							options={[
								{ value: ALL_SOURCES, label: t('common.all', 'All') },
								...availableSources.map((s) => ({
									value: s.source,
									label: s.name,
								})),
							]}
						/>
					</div>
				)}
			</div>

			{entries.length === 0 ? (
				<p className="wcpos:text-sm wcpos:text-gray-500">
					{t('logs.no_entries', 'No log entries found.')}
				</p>
			) : (
				<div className="wcpos:space-y-4">
					{groups.map((group) => (
						<div
							key={group.label}
							className="wcpos:border wcpos:border-gray-200 wcpos:rounded-md wcpos:overflow-hidden"
						>
							<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:bg-gray-50 wcpos:px-4 wcpos:py-2 wcpos:border-b wcpos:border-gray-200">
								<span className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-700">
									{dayHeaderLabel(group.label)}
								</span>
								<Chip variant="neutral" size="xs">
									{t('logs.entries_count', { count: group.entries.length })}
								</Chip>
							</div>
							<div>
								{group.entries.map((entry, idx) => {
									const key = `${group.label}-${entry.timestamp}-${idx}`;
									const sourceSlug = entry.source || 'woocommerce-pos';
									const sourceName = sourceLookup.get(sourceSlug)?.name ?? 'WCPOS';
									return (
										<LogRow
											key={key}
											entry={entry}
											isExpanded={expandedKey === key}
											onToggle={() =>
												setExpandedKey(expandedKey === key ? null : key)
											}
											sourceName={sourceName}
										/>
									);
								})}
							</div>
						</div>
					))}
				</div>
			)}

			{totalPages > 1 && (
				<div className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:gap-2 wcpos:mt-4">
					<button
						onClick={() => {
							setPage((p) => Math.max(1, p - 1));
							setExpandedKey(null);
						}}
						disabled={page <= 1}
						className="wcpos:px-3 wcpos:py-1 wcpos:rounded wcpos:text-sm wcpos:border wcpos:border-gray-300 disabled:wcpos:opacity-50"
					>
						{t('common.previous', 'Previous')}
					</button>
					<span className="wcpos:text-sm wcpos:text-gray-600">
						{page} / {totalPages}
					</span>
					<button
						onClick={() => {
							setPage((p) => Math.min(totalPages, p + 1));
							setExpandedKey(null);
						}}
						disabled={page >= totalPages}
						className="wcpos:px-3 wcpos:py-1 wcpos:rounded wcpos:text-sm wcpos:border wcpos:border-gray-300 disabled:wcpos:opacity-50"
					>
						{t('common.next', 'Next')}
					</button>
				</div>
			)}
		</div>
	);
}

export default Logs;
