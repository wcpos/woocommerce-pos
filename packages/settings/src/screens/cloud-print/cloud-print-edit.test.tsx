import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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
});
