import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { SnackbarProvider } from '@wcpos/ui';

import { PrintQueue, type QueueResponse } from './print-queue';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

interface ApiOpts {
	path: string;
	method?: string;
	data?: unknown;
}

const HOUR = 3600;
const nowSeconds = () => Math.floor(Date.now() / 1000);

/** A queue with kitchen stuck (2 waiting, never fetched) and one failed job. */
function makeQueue(overrides: Partial<QueueResponse> = {}): QueueResponse {
	const created = new Date(Date.now() - 3 * 24 * HOUR * 1000)
		.toISOString()
		.slice(0, 19)
		.replace('T', ' ');
	return {
		jobs: [
			{
				id: 11,
				printer_id: 'kitchen',
				status: 'pending',
				order_id: 4291,
				order_number: '4291',
				order_edit_url: 'https://mystore.com/wp-admin/admin.php?page=wc-orders&id=4291',
				template_id: 'receipt',
				content_type: 'application/vnd.star.starprnt',
				created_gmt: created,
			},
			{
				id: 12,
				printer_id: 'kitchen',
				status: 'pending',
				order_id: 0,
				template_id: 'receipt',
				content_type: 'application/vnd.star.starprnt',
				created_gmt: created,
			},
			{
				id: 13,
				printer_id: 'front',
				status: 'failed',
				order_id: 0,
				template_id: 'receipt',
				content_type: 'application/xml',
				created_gmt: created,
			},
		],
		total: 3,
		page: 1,
		per_page: 20,
		summary: {
			counts: { pending: 2, claimed: 0, printed: 0, failed: 1, cancelled: 0 },
			printers: [
				{
					printer_id: 'kitchen',
					name: 'Kitchen',
					polling: true,
					pending: 2,
					oldest_pending_gmt: created,
					last_seen: 0,
				},
				{
					printer_id: 'front',
					name: 'Front counter',
					polling: true,
					pending: 0,
					oldest_pending_gmt: '',
					last_seen: nowSeconds(),
				},
			],
		},
		...overrides,
	};
}

function routeQueue(getQueue: () => QueueResponse) {
	apiFetchMock.mockImplementation((opts: ApiOpts) => {
		if (opts.path.includes('print-jobs/queue/cancel')) {
			return Promise.resolve({ cancelled: 1 });
		}
		if (opts.path.includes('print-jobs/queue')) {
			return Promise.resolve(getQueue());
		}
		if (opts.path.includes('/reprint')) {
			return Promise.resolve({ id: 99 });
		}
		return Promise.resolve({});
	});
}

function renderQueue() {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return render(
		<QueryClientProvider client={client}>
			<SnackbarProvider>
				<PrintQueue />
			</SnackbarProvider>
		</QueryClientProvider>
	);
}

beforeEach(() => {
	apiFetchMock.mockReset();
});

describe('PrintQueue', () => {
	it('renders rows with status chips, printer names, and order links', async () => {
		routeQueue(makeQueue);
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		expect(screen.getByTestId('queue-row-11')).toHaveTextContent('#4291');
		expect(screen.getByTestId('queue-row-11')).toHaveTextContent('Kitchen');
		expect(screen.getByTestId('queue-row-11')).toHaveTextContent('Waiting');
		expect(screen.getByTestId('queue-row-13')).toHaveTextContent('Failed');
		expect(screen.getByTestId('queue-retry-13')).toBeInTheDocument();
	});

	it('shows a stale-printer banner with a cancel-all action', async () => {
		routeQueue(makeQueue);
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-stale-kitchen')).toBeInTheDocument());
		expect(screen.getByTestId('queue-stale-kitchen')).toHaveTextContent('never fetched');
		expect(screen.queryByTestId('queue-stale-front')).toBeNull();

		fireEvent.click(screen.getByTestId('queue-cancel-all-kitchen'));
		await waitFor(() =>
			expect(
				apiFetchMock.mock.calls.some(
					(call) =>
						(call[0] as ApiOpts).path.includes('queue/cancel') &&
						((call[0] as ApiOpts).data as { printer_id?: string }).printer_id === 'kitchen'
				)
			).toBe(true)
		);
	});

	it('bulk-cancels only the cancellable selected jobs', async () => {
		routeQueue(makeQueue);
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		fireEvent.click(screen.getByTestId('queue-select-all'));
		// Row 13 is failed — select-all includes it, but only 11/12 are cancellable.
		fireEvent.click(screen.getByTestId('queue-cancel-selected'));

		await waitFor(() =>
			expect(
				apiFetchMock.mock.calls.some((call) => {
					const opts = call[0] as ApiOpts;
					if (!opts.path.includes('queue/cancel')) {
						return false;
					}
					const ids = (opts.data as { ids?: number[] }).ids;
					return Array.isArray(ids) && ids.length === 2 && ids.includes(11) && ids.includes(12);
				})
			).toBe(true)
		);
	});

	it('retries a failed job via reprint', async () => {
		routeQueue(makeQueue);
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-retry-13')).toBeInTheDocument());
		fireEvent.click(screen.getByTestId('queue-retry-13'));

		await waitFor(() =>
			expect(
				apiFetchMock.mock.calls.some((call) =>
					(call[0] as ApiOpts).path.includes('print-jobs/13/reprint')
				)
			).toBe(true)
		);
	});

	it('shows the replacement job instead of retrying a resolved failure', async () => {
		routeQueue(() => {
			const queue = makeQueue();
			queue.jobs[2].retried_to = 99;
			return queue;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-row-13')).toBeInTheDocument());
		expect(screen.getByTestId('queue-row-13')).toHaveTextContent('Retried as #99');
		expect(screen.queryByTestId('queue-retry-13')).toBeNull();
	});

	it('uses unresolved failures for the needs-attention count', async () => {
		routeQueue(() => {
			const queue = makeQueue();
			queue.summary.counts.failed = 2;
			queue.summary.counts.failed_unresolved = 1;
			return queue;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByText('Needs attention (3)')).toBeInTheDocument());
		expect(screen.getByText('Failed (2)')).toBeInTheDocument();
	});

	it('treats an already-retried response as success and updates the cached row', async () => {
		let retryReported = false;
		apiFetchMock.mockImplementation((opts: ApiOpts) => {
			if (opts.path.includes('/reprint')) {
				retryReported = true;
				return Promise.reject({
					code: 'wcpos_print_job_already_retried',
					data: { status: 409, retried_to: 99 },
				});
			}
			if (opts.path.includes('print-jobs/queue')) {
				// After the 409 the server knows the job was retried — the
				// reconciling refetch must not resurrect the Retry button.
				const queue = makeQueue();
				if (retryReported) {
					queue.jobs[2].retried_to = 99;
				}
				return Promise.resolve(queue);
			}
			return Promise.resolve({});
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-retry-13')).toBeInTheDocument());
		const queueFetchesBeforeRetry = apiFetchMock.mock.calls.filter((call) =>
			(call[0] as ApiOpts).path.includes('print-jobs/queue')
		).length;
		fireEvent.click(screen.getByTestId('queue-retry-13'));

		await waitFor(() =>
			expect(screen.getByTestId('queue-row-13')).toHaveTextContent('Retried as #99')
		);
		expect(screen.queryByTestId('queue-retry-13')).toBeNull();
		expect(screen.queryByText(/the queue is unchanged/i)).toBeNull();

		// The 409 path must also refetch — the optimistic patch is instant
		// feedback only, and filtered views (e.g. the active list dropping the
		// now-resolved job) reconcile from the server.
		await waitFor(() =>
			expect(
				apiFetchMock.mock.calls.filter((call) =>
					(call[0] as ApiOpts).path.includes('print-jobs/queue')
				).length
			).toBeGreaterThan(queueFetchesBeforeRetry)
		);
	});

	it('surfaces an error snackbar when a cancel request fails', async () => {
		apiFetchMock.mockImplementation((opts: ApiOpts) => {
			if (opts.path.includes('print-jobs/queue/cancel')) {
				return Promise.reject(new Error('boom'));
			}
			if (opts.path.includes('print-jobs/queue')) {
				return Promise.resolve(makeQueue());
			}
			return Promise.resolve({});
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-cancel-11')).toBeInTheDocument());
		fireEvent.click(screen.getByTestId('queue-cancel-11'));

		await waitFor(() => expect(screen.getByText(/the queue is unchanged/i)).toBeInTheDocument());
	});

	it('clamps back into range when the current page empties', async () => {
		// 21 jobs -> 2 pages. After "cancelling", the total drops to 20 and
		// page 2 no longer exists; the component must fall back to page 1.
		let total = 21;
		apiFetchMock.mockImplementation((opts: ApiOpts) => {
			if (opts.path.includes('print-jobs/queue/cancel')) {
				total = 20;
				return Promise.resolve({ cancelled: 1 });
			}
			if (opts.path.includes('print-jobs/queue')) {
				const base = makeQueue();
				const onPage2 = opts.path.includes('page=2') && total === 21;
				return Promise.resolve({
					...base,
					jobs: onPage2 ? [base.jobs[0]] : base.jobs,
					total,
				});
			}
			return Promise.resolve({});
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-page-info')).toBeInTheDocument());
		fireEvent.click(screen.getByLabelText('Next'));
		// Wait for the page-2 render (21–21 of 21), not just the request.
		await waitFor(() =>
			expect(screen.getByTestId('queue-page-info')).toHaveTextContent('21–21 of 21')
		);
		fireEvent.click(screen.getByTestId('queue-cancel-11'));

		// Total shrinks to one page; the component must re-request page 1.
		await waitFor(() => {
			const calls = apiFetchMock.mock.calls.map((c) => (c[0] as ApiOpts).path);
			const afterCancel = calls.slice(calls.findIndex((path) => path.includes('queue/cancel')));
			expect(afterCancel.some((path) => path.includes('page=1'))).toBe(true);
		});
	});

	it('requests every status by default, newest first', async () => {
		// "Needs attention" as the default hid the job that just fired, so the
		// queue could not answer the question it is usually opened to answer:
		// did my receipt go through? Ordering is the server's job (DESC) — what
		// matters here is that the client stops narrowing the view for you.
		routeQueue(makeQueue);
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		const firstQueueCall = apiFetchMock.mock.calls
			.map((c) => (c[0] as ApiOpts).path)
			.find((path) => path.includes('print-jobs/queue') && !path.includes('cancel'));
		expect(firstQueueCall).not.toContain('status=active');
		expect(
			screen.getByText('Jobs across every status, including printed and cancelled.')
		).toBeInTheDocument();
	});

	it('renders rows in the order the server returned them, newest first', async () => {
		// The server orders DESC; the table must not re-sort or reverse it. The
		// shared fixture gives every job the same created_gmt, so an ascending
		// regression would pass unnoticed — use distinct timestamps and assert
		// the rendered order.
		routeQueue(() => {
			const base = makeQueue();
			base.jobs = [
				{ ...base.jobs[0], id: 11, created_gmt: '2026-08-29 12:00:00' },
				{ ...base.jobs[1], id: 12, created_gmt: '2026-08-29 11:00:00' },
				{ ...base.jobs[2], id: 13, created_gmt: '2026-08-29 10:00:00' },
			];
			return base;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		const rendered = screen
			.getAllByTestId(/^queue-row-/)
			.map((row) => row.getAttribute('data-testid'));
		expect(rendered).toEqual(['queue-row-11', 'queue-row-12', 'queue-row-13']);
	});

	it('shows a recorded failure reason on the row', async () => {
		routeQueue(() => {
			const base = makeQueue();
			base.jobs = base.jobs.map((job) =>
				job.id === 13 ? { ...job, error: 'EX_TIMEOUT' } : job
			);
			return base;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		expect(screen.getByTestId('queue-error-13')).toHaveTextContent('EX_TIMEOUT');
	});

	it('explains an unconfirmed failure instead of showing its code', async () => {
		routeQueue(() => {
			const base = makeQueue();
			base.jobs = base.jobs.map((job) =>
				job.id === 13 ? { ...job, error: 'claim_timeout', unconfirmed: true } : job
			);
			return base;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		expect(screen.getByTestId('queue-error-13')).toHaveTextContent('never confirmed');
		expect(screen.getByTestId('queue-error-13')).not.toHaveTextContent('claim_timeout');
	});

	it('drops the retry advice from an unconfirmed failure that was already retried', async () => {
		routeQueue(() => {
			const base = makeQueue();
			base.jobs = base.jobs.map((job) =>
				job.id === 13
					? { ...job, error: 'claim_timeout', unconfirmed: true, retried_to: 21 }
					: job
			);
			return base;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		expect(screen.getByTestId('queue-error-13')).toHaveTextContent('never confirmed');
		expect(screen.getByTestId('queue-error-13')).not.toHaveTextContent('retry it if it did not print');
	});

	it('shows completion timing instead of a stale error on a printed job', async () => {
		routeQueue(() => {
			const base = makeQueue();
			base.jobs = base.jobs.map((job) =>
				job.id === 13
					? {
							...job,
							status: 'printed',
							error: 'EX_TIMEOUT',
							terminal_at: nowSeconds() - 60,
						}
					: job
			);
			return base;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		expect(screen.queryByTestId('queue-error-13')).toBeNull();
		expect(screen.getByTestId('queue-row-13')).toHaveTextContent('Printed');
		expect(screen.getByTestId('queue-row-13')).toHaveTextContent('1 minute ago');
	});

	it('deletes a single job through the bulk delete endpoint', async () => {
		routeQueue(makeQueue);
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-table')).toBeInTheDocument());
		fireEvent.click(screen.getByTestId('queue-delete-11'));

		await waitFor(() => {
			const deleteCall = apiFetchMock.mock.calls.find((c) =>
				((c[0] as ApiOpts).path ?? '').includes('queue/delete')
			);
			expect(deleteCall).toBeTruthy();
			expect((deleteCall?.[0] as ApiOpts).method).toBe('POST');
			expect((deleteCall?.[0] as { data?: { ids?: number[] } })?.data?.ids).toEqual([11]);
		});
	});

	it('never shows a stale banner for a push provider with a backlog', async () => {
		routeQueue(() => {
			const base = makeQueue();
			base.summary.printers.push({
				printer_id: 'office',
				name: 'Star Online',
				polling: false,
				pending: 4,
				oldest_pending_gmt: base.jobs[0].created_gmt,
				last_seen: 0,
			});
			return base;
		});
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-stale-kitchen')).toBeInTheDocument());
		expect(screen.queryByTestId('queue-stale-office')).toBeNull();
	});

	it('collapses to a single quiet line when nothing has ever been queued', async () => {
		routeQueue(() =>
			makeQueue({
				jobs: [],
				total: 0,
				summary: {
					counts: { pending: 0, claimed: 0, printed: 0, failed: 0, cancelled: 0 },
					printers: [],
				},
			})
		);
		renderQueue();

		await waitFor(() => expect(screen.getByTestId('queue-empty')).toBeInTheDocument());
		expect(screen.queryByTestId('queue-table')).toBeNull();
	});
});
