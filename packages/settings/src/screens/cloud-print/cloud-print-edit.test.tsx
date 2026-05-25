import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

import CloudPrint from './index';

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

	it('keeps rapid assignment edits in the next full-settings POST', async () => {
		apiFetchMock.mockResolvedValueOnce({
			printers: [{ id: 'kitchen', name: 'Kitchen', protocol: 'star-cloudprnt', store_id: 0 }],
			assignments: [{ printer_id: 'kitchen', scope: 'pos', format: 'starprnt' }],
		});
		apiFetchMock.mockImplementation(
			({ method }: { method?: string }) =>
				method === 'POST' ? new Promise(() => undefined) : Promise.resolve({ printers: [], assignments: [] })
		);

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
