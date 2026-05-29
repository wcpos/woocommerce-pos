import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { SnackbarProvider } from '@wcpos/ui';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

import CloudPrint from './index';

function deferred<T>() {
	let resolve!: (value: T) => void;
	let reject!: (reason?: unknown) => void;
	const promise = new Promise<T>((res, rej) => {
		resolve = res;
		reject = rej;
	});
	return { promise, resolve, reject };
}

interface ApiOpts {
	path: string;
	method?: string;
	data?: { printers: unknown[]; assignments: unknown[] };
}

/**
 * Path-aware api-fetch mock: the screen performs two suspense fetches (the
 * cloud-print settings GET and the receipt-templates GET) plus the settings
 * POSTs. `postSettings` receives the POSTed data and returns the server
 * response promise so tests can control resolution ordering.
 */
function routeApiFetch({
	getSettings,
	postSettings,
	templates = [],
}: {
	getSettings: () => unknown;
	postSettings: (data: ApiOpts['data']) => Promise<unknown>;
	templates?: unknown[];
}) {
	apiFetchMock.mockImplementation((opts: ApiOpts) => {
		if (opts.path.includes('/templates')) {
			return Promise.resolve(templates);
		}
		if (opts.path.includes('/settings/cloud-print')) {
			if (opts.method === 'POST') {
				return postSettings(opts.data);
			}
			return Promise.resolve(getSettings());
		}
		return Promise.resolve({});
	});
}

function postCalls() {
	return apiFetchMock.mock.calls.filter((c) => (c[0] as ApiOpts).method === 'POST');
}

function lastPostData() {
	const calls = postCalls();
	return (calls[calls.length - 1][0] as ApiOpts).data!;
}

function renderScreen() {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return {
		client,
		...render(
			<QueryClientProvider client={client}>
				<SnackbarProvider>
					<React.Suspense fallback="loading">
						<CloudPrint />
					</React.Suspense>
				</SnackbarProvider>
			</QueryClientProvider>
		),
	};
}

/** Drive the add-printer wizard through step 0 (Star) and step 1 (name). */
function fillWizard(name: string) {
	fireEvent.click(screen.getByTestId('cloud-print-add'));
	fireEvent.click(screen.getByTestId('provider-choice-star-cloudprnt'));
	fireEvent.click(screen.getByTestId('wizard-continue'));
	fireEvent.change(screen.getByTestId('wizard-name-input'), { target: { value: name } });
	fireEvent.click(screen.getByTestId('wizard-continue'));
}

beforeEach(() => {
	apiFetchMock.mockReset();
	(window as unknown as { wpApiSettings?: { root?: string } }).wpApiSettings = {
		root: 'https://mystore.com/wp-json/',
	};
	Object.defineProperty(navigator, 'clipboard', {
		value: { writeText: vi.fn() },
		configurable: true,
		writable: true,
	});
});

afterEach(() => {
	delete (window as unknown as { wpApiSettings?: unknown }).wpApiSettings;
});

describe('CloudPrint editing', () => {
	it('adds a printer via the wizard, POSTs the full settings with no real id, and surfaces the one-time token', async () => {
		routeApiFetch({
			getSettings: () => ({ printers: [], assignments: [] }),
			postSettings: () =>
				Promise.resolve({
					printers: [
						{ id: 'server-kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 },
					],
					assignments: [],
					generated: { 'server-kitchen': 'poll-token-123' },
				}),
		});

		renderScreen();
		expect(await screen.findByTestId('cloud-print-empty')).toBeTruthy();

		fillWizard('Kitchen');

		// A POST happened whose new printer carries an empty (server-derived) id.
		await waitFor(() => expect(postCalls().length).toBe(1));
		const posted = lastPostData() as { printers: Array<{ id: string }> };
		expect(posted.printers[posted.printers.length - 1].id).toBe('');

		// The wizard step 2 surfaces the one-time token.
		expect(await screen.findByTestId('wizard-poll-token')).toHaveTextContent('poll-token-123');

		// The server-derived printer card appears once the save commits.
		expect(await screen.findByTestId('printer-card-server-kitchen')).toBeTruthy();
	});

	it('serializes full-settings saves so an earlier printer registration is folded into a later rule edit', async () => {
		const firstSave = deferred<unknown>();
		routeApiFetch({
			getSettings: () => ({ printers: [], assignments: [] }),
			postSettings: (data) => {
				if ((data!.assignments as unknown[]).length === 0) {
					return firstSave.promise;
				}
				return Promise.resolve({
					printers: [
						{ id: 'kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 },
					],
					assignments: [{ printer_id: 'kitchen', scope: 'every', template_id: '1' }],
				});
			},
			templates: [{ id: 1, title: 'Receipt', status: 'publish', is_active: true, engine: 'thermal' }],
		});

		renderScreen();
		expect(await screen.findByTestId('cloud-print-empty')).toBeTruthy();

		// 1) Add a printer (first save left pending/deferred).
		fillWizard('Kitchen');
		await waitFor(() => expect(postCalls().length).toBe(1));

		// 2) The wizard reached step 2 once the printer optimistically exists; close it.
		fireEvent.click(screen.getByTestId('wizard-back'));

		// Resolve the first save with the server-assigned id.
		firstSave.resolve({
			printers: [{ id: 'kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 }],
			assignments: [],
		});
		expect(await screen.findByTestId('printer-card-kitchen')).toBeTruthy();

		// 3) Add an auto-print rule — this triggers the second (serialized) save.
		fireEvent.click(screen.getByTestId('rules-add'));

		await waitFor(() => expect(postCalls().length).toBe(2));

		// The second POST body includes the committed printer from the first save.
		expect(lastPostData()).toMatchObject({
			printers: [{ id: 'kitchen' }],
			assignments: [{ printer_id: 'kitchen' }],
		});
	});

	it('rolls back an optimistic rule edit and shows an error snackbar when the save fails', async () => {
		routeApiFetch({
			getSettings: () => ({
				printers: [
					{ id: 'kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 },
				],
				assignments: [],
			}),
			postSettings: () => Promise.reject({ message: 'Could not save rule.' }),
			templates: [{ id: 1, title: 'Receipt', status: 'publish', is_active: true, engine: 'thermal' }],
		});

		renderScreen();
		expect(await screen.findByTestId('printer-card-kitchen')).toBeTruthy();
		expect(screen.getByTestId('rules-empty')).toBeTruthy();

		// Optimistically add a rule; the POST rejects.
		fireEvent.click(screen.getByTestId('rules-add'));

		await waitFor(() => expect(postCalls().length).toBe(1));

		// The optimistic rule is rolled back.
		await waitFor(() => expect(screen.getByTestId('rules-empty')).toBeTruthy());
		expect(screen.queryByTestId('rule-0')).toBeNull();

		// An error snackbar (in the live region) surfaces the failure message.
		const liveRegion = document.querySelector('[role="status"]') as HTMLElement;
		await waitFor(() =>
			expect(within(liveRegion).getByText('Could not save rule.')).toBeTruthy()
		);
	});

	it('rolls back to the last confirmed server snapshot after queued saves fail', async () => {
		const firstSave = deferred<unknown>();
		const secondSave = deferred<unknown>();
		let postIndex = 0;
		routeApiFetch({
			getSettings: () => ({
				printers: [
					{ id: 'kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 },
				],
				assignments: [],
			}),
			postSettings: () => {
				postIndex += 1;
				return postIndex === 1 ? firstSave.promise : secondSave.promise;
			},
			templates: [{ id: 1, title: 'Receipt', status: 'publish', is_active: true, engine: 'thermal' }],
		});

		renderScreen();
		expect(await screen.findByTestId('printer-card-kitchen')).toBeTruthy();
		expect(screen.getByTestId('rules-empty')).toBeTruthy();

		// Two rapid rule edits queue two serialized saves. Saves run one at a
		// time, so the first POST goes out immediately while the second is held
		// behind it in the queue.
		fireEvent.click(screen.getByTestId('rules-add'));
		await waitFor(() => expect(postCalls().length).toBe(1));
		expect(await screen.findByTestId('rule-0')).toBeTruthy();

		fireEvent.change(screen.getByTestId('rule-scope-0'), { target: { value: 'pos' } });

		// Fail the first save → the queue drains and the second POST is sent.
		firstSave.reject(new Error('First failed.'));
		await waitFor(() => expect(postCalls().length).toBe(2));

		// Fail the second (latest) save too.
		secondSave.reject(new Error('Second failed.'));

		// Everything rolls back to the last confirmed (no-rules) snapshot.
		await waitFor(() => expect(screen.getByTestId('rules-empty')).toBeTruthy());
		expect(screen.queryByTestId('rule-0')).toBeNull();
	});
});
