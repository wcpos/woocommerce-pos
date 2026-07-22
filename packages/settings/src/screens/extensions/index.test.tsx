import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Extensions, { type Extension } from './index';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));
vi.mock('../../lib/analytics', () => ({
	captureUpgradeCtaClicked: vi.fn(),
	captureUpgradeCtaViewed: vi.fn(),
}));

const oldExtension = {
	slug: 'old-extension',
	name: 'Old Extension',
	description: '',
	category: 'other',
	tags: [],
	status: 'active',
} as Extension;

const newExtension = {
	...oldExtension,
	slug: 'new-extension',
	name: 'New Extension',
} as Extension;

function renderScreen() {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return render(
		<QueryClientProvider client={client}>
			<React.Suspense fallback="loading">
				<Extensions />
			</React.Suspense>
		</QueryClientProvider>
	);
}

beforeEach(() => {
	apiFetchMock.mockReset();
	delete window.wcpos;
});

describe('Extensions screen refresh', () => {
	it('fetches the catalog with force and replaces the displayed extensions', async () => {
		apiFetchMock.mockResolvedValueOnce([oldExtension]).mockResolvedValueOnce([newExtension]);
		renderScreen();

		fireEvent.click(await screen.findByRole('button', { name: 'Refresh' }));

		await screen.findByText('New Extension');
		expect(screen.queryByText('Old Extension')).toBeNull();
		expect(apiFetchMock).toHaveBeenLastCalledWith({
			path: 'wcpos/v1/extensions?wcpos=1&force=1',
			method: 'GET',
		});
	});

	it('keeps the displayed extensions and shows an error when refresh fails', async () => {
		apiFetchMock
			.mockResolvedValueOnce([oldExtension])
			.mockRejectedValueOnce(new Error('failed'))
			.mockResolvedValueOnce([newExtension]);
		renderScreen();

		fireEvent.click(await screen.findByRole('button', { name: 'Refresh' }));

		await waitFor(() =>
			expect(screen.getByText('Could not refresh extensions. Please try again.')).toBeTruthy()
		);
		expect(screen.getByText('Old Extension')).toBeTruthy();

		fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));

		await screen.findByText('New Extension');
		expect(screen.queryByText('Could not refresh extensions. Please try again.')).toBeNull();
		expect(screen.getByText('New Extension')).toBeTruthy();
	});

	it('keeps the displayed extensions and shows an error when refresh returns empty', async () => {
		apiFetchMock.mockResolvedValueOnce([oldExtension]).mockResolvedValueOnce([]);
		renderScreen();

		fireEvent.click(await screen.findByRole('button', { name: 'Refresh' }));

		await waitFor(() =>
			expect(screen.getByText('Could not refresh extensions. Please try again.')).toBeTruthy()
		);
		expect(screen.getByText('Old Extension')).toBeTruthy();
	});
});
