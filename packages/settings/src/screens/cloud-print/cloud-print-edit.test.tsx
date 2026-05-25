import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

import CloudPrint from './index';

function deferred<T>() {
	let resolve!: (value: T) => void;
	const promise = new Promise<T>((res) => {
		resolve = res;
	});
	return { promise, resolve };
}

function renderScreen() {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return render(
		<QueryClientProvider client={client}>
			<React.Suspense fallback="loading">
				<CloudPrint />
			</React.Suspense>
		</QueryClientProvider>
	);
}

describe('CloudPrint editing', () => {
	beforeEach(() => {
		apiFetchMock.mockReset();
	});
	it('adds a printer and POSTs the full settings object', async () => {
		apiFetchMock.mockResolvedValueOnce({ printers: [], assignments: [] });
		apiFetchMock.mockResolvedValueOnce({ printers: [{ id: 'kitchen' }], assignments: [] });

		renderScreen();
		expect(await screen.findByTestId('cloud-print-empty')).toBeTruthy();

		fireEvent.change(screen.getByTestId('cloud-printer-id-input'), { target: { value: 'kitchen' } });
		fireEvent.change(screen.getByTestId('cloud-printer-name-input'), { target: { value: 'Kitchen' } });
		fireEvent.click(screen.getByTestId('cloud-printer-add'));

		await waitFor(() => {
			const postCall = apiFetchMock.mock.calls.find(
				(c) => (c[0] as { method?: string }).method === 'POST'
			);
			expect(postCall).toBeTruthy();
		});

		const postCall = apiFetchMock.mock.calls.find(
			(c) => (c[0] as { method?: string }).method === 'POST'
		);
		expect((postCall![0] as { data: { printers: Array<{ id: string }> } }).data.printers[0].id).toBe(
			'kitchen'
		);
	});


	it('rolls back a printer add when the save fails', async () => {
		apiFetchMock.mockResolvedValueOnce({ printers: [], assignments: [] });
		apiFetchMock.mockRejectedValueOnce(new Error('Duplicate printer id.'));

		renderScreen();
		expect(await screen.findByTestId('cloud-print-empty')).toBeTruthy();

		fireEvent.change(screen.getByTestId('cloud-printer-id-input'), { target: { value: 'kitchen' } });
		fireEvent.click(screen.getByTestId('cloud-printer-add'));

		await waitFor(() => {
			expect(apiFetchMock.mock.calls.some((c) => (c[0] as { method?: string }).method === 'POST')).toBe(
				true
			);
		});
		expect(screen.queryByTestId('cloud-printer-kitchen')).toBeNull();
		expect(screen.getByTestId('cloud-print-empty')).toBeTruthy();
		expect(await screen.findByTestId('cloud-print-save-error')).toHaveTextContent('Duplicate printer id.');
	});

	it('keeps rapid assignment edits in the next full-settings POST', async () => {
		apiFetchMock.mockResolvedValueOnce({
			printers: [{ id: 'kitchen', name: 'Kitchen', protocol: 'star-cloudprnt', store_id: 0 }],
			assignments: [{ printer_id: 'kitchen', scope: 'pos', format: 'starprnt' }],
		});
		apiFetchMock.mockResolvedValue({
			printers: [{ id: 'kitchen', name: 'Kitchen', protocol: 'star-cloudprnt', store_id: 0 }],
			assignments: [{ printer_id: 'kitchen', scope: 'online', format: 'escpos' }],
		});

		renderScreen();
		expect(await screen.findByTestId('cloud-assignment-0')).toBeTruthy();

		fireEvent.change(screen.getByTestId('cloud-assignment-scope-0'), { target: { value: 'online' } });
		fireEvent.change(screen.getByTestId('cloud-assignment-format-0'), { target: { value: 'escpos' } });

		await waitFor(() => {
			const postCalls = apiFetchMock.mock.calls.filter(
				(c) => (c[0] as { method?: string }).method === 'POST'
			);
			expect(postCalls.length).toBe(2);
		});

		const postCalls = apiFetchMock.mock.calls.filter((c) => (c[0] as { method?: string }).method === 'POST');
		expect(
			(postCalls[1][0] as { data: { assignments: Array<{ scope: string; format: string }> } }).data
				.assignments[0]
		).toMatchObject({ scope: 'online', format: 'escpos' });
	});

	it('serializes full-settings saves so older printer registrations cannot overwrite later rule edits', async () => {
		const firstSave = deferred<{ printers: Array<{ id: string }>; assignments: never[] }>();
		const secondSave = deferred<{
			printers: Array<{ id: string; name: string; protocol: string; store_id: number }>;
			assignments: Array<{ printer_id: string; scope: string; format: string }>;
		}>();
		apiFetchMock.mockResolvedValueOnce({ printers: [], assignments: [] });
		apiFetchMock.mockReturnValueOnce(firstSave.promise);
		apiFetchMock.mockReturnValueOnce(secondSave.promise);

		renderScreen();
		expect(await screen.findByTestId('cloud-print-empty')).toBeTruthy();

		fireEvent.change(screen.getByTestId('cloud-printer-id-input'), { target: { value: 'kitchen' } });
		fireEvent.change(screen.getByTestId('cloud-printer-name-input'), { target: { value: 'Kitchen' } });
		fireEvent.click(screen.getByTestId('cloud-printer-add'));
		expect(await screen.findByTestId('cloud-printer-kitchen')).toBeTruthy();

		fireEvent.click(screen.getByTestId('cloud-assignment-add'));
		expect(await screen.findByTestId('cloud-assignment-0')).toBeTruthy();

		await waitFor(() => {
			const postCalls = apiFetchMock.mock.calls.filter(
				(c) => (c[0] as { method?: string }).method === 'POST'
			);
			expect(postCalls.length).toBe(1);
		});

		firstSave.resolve({
			printers: [{ id: 'kitchen' }],
			assignments: [],
		});

		await waitFor(() => {
			const postCalls = apiFetchMock.mock.calls.filter(
				(c) => (c[0] as { method?: string }).method === 'POST'
			);
			expect(postCalls.length).toBe(2);
		});

		const postCalls = apiFetchMock.mock.calls.filter((c) => (c[0] as { method?: string }).method === 'POST');
		expect(
			(postCalls[1][0] as {
				data: { printers: Array<{ id: string }>; assignments: Array<{ printer_id: string }> };
			}).data
		).toMatchObject({
			printers: [{ id: 'kitchen' }],
			assignments: [{ printer_id: 'kitchen' }],
		});

		secondSave.resolve({
			printers: [{ id: 'kitchen', name: 'Kitchen', protocol: 'star-cloudprnt', store_id: 0 }],
			assignments: [{ printer_id: 'kitchen', scope: 'pos', format: 'starprnt' }],
		});
	});

	it('defaults Epson Server Direct Print assignments to epos-xml', async () => {
		apiFetchMock.mockResolvedValueOnce({
			printers: [{ id: 'epson', name: 'Epson', protocol: 'epson-sdp', store_id: 0 }],
			assignments: [],
		});
		apiFetchMock.mockResolvedValueOnce({
			printers: [{ id: 'epson', name: 'Epson', protocol: 'epson-sdp', store_id: 0 }],
			assignments: [{ printer_id: 'epson', scope: 'pos', format: 'epos-xml' }],
		});

		renderScreen();
		expect(await screen.findByTestId('cloud-printer-epson')).toBeTruthy();
		fireEvent.click(screen.getByTestId('cloud-assignment-add'));

		await waitFor(() => {
			const postCall = apiFetchMock.mock.calls.find(
				(c) => (c[0] as { method?: string }).method === 'POST'
			);
			expect(
				(postCall?.[0] as { data?: { assignments: Array<{ format: string }> } } | undefined)?.data
					?.assignments[0]?.format
			).toBe('epos-xml');
		});
	});

});
