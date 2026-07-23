import * as React from 'react';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import {
	Button,
	Chip,
	FormSection,
	Notice,
	Select,
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableHeaderRow,
	TableRow,
	type ChipVariant,
} from '@wcpos/ui';

import { t } from '../../translations';

const QUEUE_ENDPOINT = 'wcpos/v1/print-jobs/queue';
const QUEUE_QUERY_KEY = 'print-queue';
const PER_PAGE = 20;
const REFETCH_MS = 30_000;

// A printer with waiting jobs that hasn't fetched for this long (or ever) is
// considered stuck and gets the warning banner. Polling printers hit the site
// at least every couple of minutes when healthy.
const STALE_AFTER_SECONDS = 10 * 60;

export interface QueueJob {
	id: number;
	printer_id: string;
	status: 'pending' | 'claimed' | 'printed' | 'failed' | 'cancelled';
	order_id: number;
	order_number?: string;
	order_edit_url?: string;
	template_id: string;
	content_type: string;
	created_gmt: string;
}

export interface QueueSummaryPrinter {
	printer_id: string;
	name: string;
	pending: number;
	oldest_pending_gmt: string;
	/** Unix seconds of the printer's last fetch; 0 = never. */
	last_seen: number;
}

export interface QueueResponse {
	jobs: QueueJob[];
	total: number;
	page: number;
	per_page: number;
	summary: {
		counts: Record<string, number>;
		printers: QueueSummaryPrinter[];
	};
}

const STATUS_META: Record<QueueJob['status'], { label: () => string; variant: ChipVariant }> = {
	pending: { label: () => t('cloud_print.queue_status_waiting', 'Waiting'), variant: 'warning' },
	claimed: { label: () => t('cloud_print.queue_status_printing', 'Printing'), variant: 'info' },
	printed: { label: () => t('cloud_print.queue_status_printed', 'Printed'), variant: 'success' },
	failed: { label: () => t('cloud_print.queue_status_failed', 'Failed'), variant: 'error' },
	cancelled: {
		label: () => t('cloud_print.queue_status_cancelled', 'Cancelled'),
		variant: 'neutral',
	},
};

/** Parse a MySQL-format GMT timestamp into a Date. */
function parseGmt(gmt: string): Date | null {
	if (!gmt || gmt.startsWith('0000')) {
		return null;
	}
	const date = new Date(`${gmt.replace(' ', 'T')}Z`);
	return Number.isNaN(date.getTime()) ? null : date;
}

/** Localized "3 days ago"-style age for a Date. */
function relativeAge(date: Date): string {
	const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
	const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
	if (seconds < 60) {
		return rtf.format(-seconds, 'second');
	}
	if (seconds < 3600) {
		return rtf.format(-Math.round(seconds / 60), 'minute');
	}
	if (seconds < 86400) {
		return rtf.format(-Math.round(seconds / 3600), 'hour');
	}
	return rtf.format(-Math.round(seconds / 86400), 'day');
}

/** Localized age for a MySQL-format GMT timestamp. */
function timeAgo(gmt: string): string {
	const date = parseGmt(gmt);
	return date ? relativeAge(date) : '—';
}

function isStale(printer: QueueSummaryPrinter): boolean {
	if (printer.pending === 0) {
		return false;
	}
	if (printer.last_seen === 0) {
		return true;
	}
	return Date.now() / 1000 - printer.last_seen > STALE_AFTER_SECONDS;
}

/**
 * The Cloud Print queue: warning banner for stuck printers, filterable table
 * of jobs across all printers, bulk cancel, pagination. Collapses to a single
 * quiet line when there is nothing queued.
 */
export function PrintQueue() {
	const queryClient = useQueryClient();
	const [printerFilter, setPrinterFilter] = React.useState('');
	const [statusFilter, setStatusFilter] = React.useState('');
	const [page, setPage] = React.useState(1);
	const [selected, setSelected] = React.useState<Set<number>>(new Set());

	const { data } = useQuery<QueueResponse>({
		queryKey: [QUEUE_QUERY_KEY, printerFilter, statusFilter, page],
		queryFn: () =>
			apiFetch({
				path: `${QUEUE_ENDPOINT}?wcpos=1&per_page=${PER_PAGE}&page=${page}${
					printerFilter ? `&printer_id=${encodeURIComponent(printerFilter)}` : ''
				}${statusFilter ? `&status=${encodeURIComponent(statusFilter)}` : ''}`,
				method: 'GET',
			}) as Promise<QueueResponse>,
		refetchInterval: REFETCH_MS,
	});

	const invalidate = () => {
		setSelected(new Set());
		void queryClient.invalidateQueries({ queryKey: [QUEUE_QUERY_KEY] });
	};

	const cancelJobs = useMutation({
		mutationFn: (body: { ids?: number[]; printer_id?: string }) =>
			apiFetch({
				path: `${QUEUE_ENDPOINT}/cancel?wcpos=1`,
				method: 'POST',
				data: body,
			}) as Promise<{ cancelled: number }>,
		onSuccess: invalidate,
	});

	const retryJob = useMutation({
		mutationFn: (id: number) =>
			apiFetch({ path: `wcpos/v1/print-jobs/${id}/reprint?wcpos=1`, method: 'POST' }),
		onSuccess: invalidate,
	});

	// Also guards a malformed response — a queue view that can't render is
	// invisible, never a crashed settings screen.
	if (!data || !Array.isArray(data.jobs) || !data.summary) {
		return null;
	}

	const { jobs, total, summary } = data;
	const printerNames = new Map(summary.printers.map((p) => [p.printer_id, p.name]));
	const stalePrinters = summary.printers.filter(isStale);
	const counts = summary.counts;
	const hasAnyJobs =
		Object.values(counts).some((n) => n > 0) || total > 0 || printerFilter !== '' || statusFilter !== '';

	const pageIds = jobs.map((j) => j.id);
	const allOnPageSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));
	const toggleAll = () => {
		setSelected(allOnPageSelected ? new Set() : new Set(pageIds));
	};
	const toggleOne = (id: number) => {
		const next = new Set(selected);
		if (next.has(id)) {
			next.delete(id);
		} else {
			next.add(id);
		}
		setSelected(next);
	};
	// Only waiting jobs can be cancelled — printed/failed rows may be selected
	// via "select all" but are never sent.
	const cancellableSelected = jobs
		.filter((j) => selected.has(j.id) && (j.status === 'pending' || j.status === 'claimed'))
		.map((j) => j.id);

	const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));

	const statusOptions = [
		{ label: t('cloud_print.queue_all_statuses', 'All statuses'), value: '' },
		...(
			['pending', 'claimed', 'failed', 'printed', 'cancelled'] as QueueJob['status'][]
		).map((status) => ({
			label: `${STATUS_META[status].label()} (${counts[status] ?? 0})`,
			value: status,
		})),
	];
	const printerOptions = [
		{ label: t('cloud_print.queue_all_printers', 'All printers'), value: '' },
		...summary.printers.map((p) => ({ label: p.name, value: p.printer_id })),
	];

	return (
		<FormSection
			title={t('cloud_print.queue_title', 'Print queue')}
			description={t('cloud_print.queue_description', 'Jobs waiting to be fetched by your printers.')}
		>
			{stalePrinters.map((printer) => (
				<Notice key={printer.printer_id} status="warning" isDismissible={false}>
					<div
						className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:flex-wrap"
						data-testid={`queue-stale-${printer.printer_id}`}
					>
						<span>
							{printer.last_seen === 0
								? t(
										'cloud_print.queue_stale_never',
										'{printer} has never fetched a job. {count} receipts are waiting — check the printer is on and its Server URL is set.',
										{ printer: printer.name, count: String(printer.pending) }
									)
								: t(
										'cloud_print.queue_stale',
										'{printer} last fetched jobs {ago}. {count} receipts are waiting — check the printer is on and connected.',
										{
											printer: printer.name,
											ago: relativeAge(new Date(printer.last_seen * 1000)),
											count: String(printer.pending),
										}
									)}
						</span>
						<Button
							variant="outline"
							data-testid={`queue-cancel-all-${printer.printer_id}`}
							onClick={() => cancelJobs.mutate({ printer_id: printer.printer_id })}
							disabled={cancelJobs.isPending}
						>
							{t('cloud_print.queue_cancel_all', 'Cancel all {count}', {
								count: String(printer.pending),
							})}
						</Button>
					</div>
				</Notice>
			))}

			{!hasAnyJobs ? (
				<p className="wcpos:text-sm wcpos:text-gray-500" data-testid="queue-empty">
					{t('cloud_print.queue_empty', 'No print jobs yet.')}
				</p>
			) : (
				<>
					<div className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:flex-wrap">
						<Select
							id="wcpos-queue-printer-filter"
							aria-label={t('cloud_print.queue_filter_printer', 'Filter by printer')}
							options={printerOptions}
							value={printerFilter}
							onChange={(option) => {
								setPrinterFilter(String(option.value));
								setPage(1);
								setSelected(new Set());
							}}
						/>
						<Select
							id="wcpos-queue-status-filter"
							aria-label={t('cloud_print.queue_filter_status', 'Filter by status')}
							options={statusOptions}
							value={statusFilter}
							onChange={(option) => {
								setStatusFilter(String(option.value));
								setPage(1);
								setSelected(new Set());
							}}
						/>
						<div className="wcpos:flex-1" />
						{cancellableSelected.length > 0 && (
							<Button
								variant="outline"
								data-testid="queue-cancel-selected"
								onClick={() => cancelJobs.mutate({ ids: cancellableSelected })}
								disabled={cancelJobs.isPending}
							>
								{t('cloud_print.queue_cancel_selected', 'Cancel selected ({count})', {
									count: String(cancellableSelected.length),
								})}
							</Button>
						)}
					</div>

					{jobs.length === 0 ? (
						<p className="wcpos:text-sm wcpos:text-gray-500" data-testid="queue-no-matches">
							{t('cloud_print.queue_no_matches', 'No jobs match this filter.')}
						</p>
					) : (
						<div className="wcpos:overflow-x-auto">
							<Table data-testid="queue-table">
								<TableHeader>
									<TableHeaderRow>
										<TableHead className="wcpos:w-8">
											<input
												type="checkbox"
												checked={allOnPageSelected}
												onChange={toggleAll}
												aria-label={t('cloud_print.queue_select_all', 'Select all on this page')}
												data-testid="queue-select-all"
											/>
										</TableHead>
										<TableHead>{t('cloud_print.queue_col_order', 'Order')}</TableHead>
										<TableHead>{t('cloud_print.queue_col_printer', 'Printer')}</TableHead>
										<TableHead>{t('cloud_print.queue_col_status', 'Status')}</TableHead>
										<TableHead>{t('cloud_print.queue_col_waiting', 'Waiting')}</TableHead>
										<TableHead />
									</TableHeaderRow>
								</TableHeader>
								<TableBody>
									{jobs.map((job) => {
										const meta = STATUS_META[job.status] ?? STATUS_META.pending;
										const waiting =
											job.status === 'pending' || job.status === 'claimed'
												? timeAgo(job.created_gmt)
												: '—';
										return (
											<TableRow key={job.id} data-testid={`queue-row-${job.id}`}>
												<TableCell>
													<input
														type="checkbox"
														checked={selected.has(job.id)}
														onChange={() => toggleOne(job.id)}
														aria-label={t('cloud_print.queue_select_job', 'Select job {id}', {
															id: String(job.id),
														})}
													/>
												</TableCell>
												<TableCell>
													{job.order_edit_url ? (
														<a href={job.order_edit_url} target="_blank" rel="noopener noreferrer">
															#{job.order_number ?? job.order_id}
														</a>
													) : (
														<span className="wcpos:text-gray-500">
															{t('cloud_print.queue_no_order', 'Job {id}', { id: String(job.id) })}
														</span>
													)}
												</TableCell>
												<TableCell>{printerNames.get(job.printer_id) ?? job.printer_id}</TableCell>
												<TableCell>
													<Chip variant={meta.variant} shape="pill" size="sm">
														{meta.label()}
													</Chip>
												</TableCell>
												<TableCell className="wcpos:tabular-nums wcpos:text-gray-600">
													{waiting}
												</TableCell>
												<TableCell>
													{(job.status === 'pending' || job.status === 'claimed') && (
														<button
															className="wcpos:text-sm wcpos:text-blue-700 hover:wcpos:underline"
															onClick={() => cancelJobs.mutate({ ids: [job.id] })}
															disabled={cancelJobs.isPending}
															data-testid={`queue-cancel-${job.id}`}
														>
															{t('cloud_print.queue_cancel', 'Cancel')}
														</button>
													)}
													{job.status === 'failed' && (
														<button
															className="wcpos:text-sm wcpos:text-blue-700 hover:wcpos:underline"
															onClick={() => retryJob.mutate(job.id)}
															disabled={retryJob.isPending}
															data-testid={`queue-retry-${job.id}`}
														>
															{t('cloud_print.queue_retry', 'Retry')}
														</button>
													)}
												</TableCell>
											</TableRow>
										);
									})}
								</TableBody>
							</Table>
						</div>
					)}

					{totalPages > 1 && (
						<div className="wcpos:flex wcpos:items-center wcpos:justify-end wcpos:gap-3 wcpos:text-sm wcpos:text-gray-600">
							<span data-testid="queue-page-info">
								{t('cloud_print.queue_page_info', '{from}–{to} of {total}', {
									from: String((page - 1) * PER_PAGE + 1),
									to: String(Math.min(page * PER_PAGE, total)),
									total: String(total),
								})}
							</span>
							<Button
								variant="outline"
								onClick={() => setPage((p) => Math.max(1, p - 1))}
								disabled={page <= 1}
								aria-label={t('common.previous', 'Previous')}
							>
								‹
							</Button>
							<Button
								variant="outline"
								onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
								disabled={page >= totalPages}
								aria-label={t('common.next', 'Next')}
							>
								›
							</Button>
						</div>
					)}
				</>
			)}
		</FormSection>
	);
}
