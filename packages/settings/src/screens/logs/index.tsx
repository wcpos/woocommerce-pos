import * as React from 'react';

import { useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { markLogsRead } from './use-unread-log-counts';
import Notice from '../../components/notice';
import { t } from '../../translations';

interface LogEntry {
	timestamp: string;
	level: string;
	message: string;
	context: string;
}

interface LogsResponse {
	entries: LogEntry[];
	has_fatal_errors: boolean;
	fatal_errors_url: string;
}

const LEVEL_STYLES: Record<string, string> = {
	error: 'wcpos:bg-red-100 wcpos:text-red-800',
	critical: 'wcpos:bg-red-100 wcpos:text-red-800',
	emergency: 'wcpos:bg-red-100 wcpos:text-red-800',
	alert: 'wcpos:bg-red-100 wcpos:text-red-800',
	warning: 'wcpos:bg-amber-100 wcpos:text-amber-800',
	info: 'wcpos:bg-blue-100 wcpos:text-blue-800',
	notice: 'wcpos:bg-blue-100 wcpos:text-blue-800',
	debug: 'wcpos:bg-gray-100 wcpos:text-gray-600',
};

function Logs() {
	const [filter, setFilter] = React.useState<string>('all');
	const [expandedIndex, setExpandedIndex] = React.useState<number | null>(null);
	const [page, setPage] = React.useState(1);

	const levelParam = filter === 'all' ? '' : `&level=${filter}`;

	const { data } = useSuspenseQuery<LogsResponse>({
		queryKey: ['logs', filter, page],
		queryFn: () =>
			apiFetch({
				path: `wcpos/v1/logs?wcpos=1&per_page=50&page=${page}${levelParam}`,
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
	const totalPages = (data as any)?._totalPages ?? 1;

	React.useEffect(() => {
		markLogsRead();
	}, []);

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

			{/* Filter bar */}
			<div className="wcpos:flex wcpos:gap-2 wcpos:mb-4">
				{filters.map((f) => (
					<button
						key={f.key}
						onClick={() => {
							setFilter(f.key);
							setPage(1);
							setExpandedIndex(null);
						}}
						className={`wcpos:px-3 wcpos:py-1 wcpos:rounded-full wcpos:text-sm wcpos:font-medium wcpos:transition-colors ${
							filter === f.key
								? 'wcpos:bg-wp-admin-theme-color wcpos:text-white'
								: 'wcpos:bg-gray-100 wcpos:text-gray-600 hover:wcpos:bg-gray-200'
						}`}
					>
						{f.label}
					</button>
				))}
			</div>

			{/* Entry list */}
			{entries.length === 0 ? (
				<p className="wcpos:text-sm wcpos:text-gray-500">
					{t('logs.no_entries', 'No log entries found.')}
				</p>
			) : (
				<div className="wcpos:space-y-1">
					{entries.map((entry, index) => {
						const isExpanded = expandedIndex === index;
						const isLong = entry.message.length > 100 || !!entry.context;
						const displayMessage = isExpanded
							? entry.message
							: entry.message.slice(0, 100) + (entry.message.length > 100 ? '...' : '');

						return (
							<div
								key={`${entry.timestamp}-${index}`}
								className="wcpos:flex wcpos:flex-col wcpos:border wcpos:border-gray-200 wcpos:rounded-md wcpos:px-3 wcpos:py-2"
							>
								<div
									className={`wcpos:flex wcpos:items-start wcpos:gap-3 ${isLong ? 'wcpos:cursor-pointer' : ''}`}
									onClick={() => isLong && setExpandedIndex(isExpanded ? null : index)}
								>
									<span
										className={`wcpos:inline-flex wcpos:items-center wcpos:px-2 wcpos:py-0.5 wcpos:rounded wcpos:text-xs wcpos:font-medium wcpos:shrink-0 ${
											LEVEL_STYLES[entry.level] || LEVEL_STYLES.debug
										}`}
									>
										{entry.level}
									</span>
									<span className="wcpos:text-xs wcpos:text-gray-400 wcpos:shrink-0 wcpos:font-mono">
										{entry.timestamp}
									</span>
									<span className="wcpos:text-sm wcpos:text-gray-700 wcpos:break-all">
										{displayMessage}
									</span>
								</div>
								{isExpanded && entry.context && (
									<div className="wcpos:mt-2 wcpos:ml-16 wcpos:p-2 wcpos:bg-gray-50 wcpos:rounded wcpos:text-xs wcpos:text-gray-600 wcpos:font-mono wcpos:whitespace-pre-wrap">
										{entry.context}
									</div>
								)}
							</div>
						);
					})}
				</div>
			)}

			{/* Pagination */}
			{totalPages > 1 && (
				<div className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:gap-2 wcpos:mt-4">
					<button
						onClick={() => {
							setPage((p) => Math.max(1, p - 1));
							setExpandedIndex(null);
						}}
						disabled={page <= 1}
						className="wcpos:px-3 wcpos:py-1 wcpos:rounded wcpos:text-sm wcpos:border wcpos:border-gray-300 disabled:wcpos:opacity-50"
					>
						Previous
					</button>
					<span className="wcpos:text-sm wcpos:text-gray-600">
						{page} / {totalPages}
					</span>
					<button
						onClick={() => {
							setPage((p) => Math.min(totalPages, p + 1));
							setExpandedIndex(null);
						}}
						disabled={page >= totalPages}
						className="wcpos:px-3 wcpos:py-1 wcpos:rounded wcpos:text-sm wcpos:border wcpos:border-gray-300 disabled:wcpos:opacity-50"
					>
						Next
					</button>
				</div>
			)}
		</div>
	);
}

export default Logs;
