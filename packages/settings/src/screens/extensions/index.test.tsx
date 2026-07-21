import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({
	default: (...args: unknown[]) => apiFetchMock(...args),
}));

vi.mock('../../lib/analytics', () => ({
	captureUpgradeCtaClicked: vi.fn(),
	captureUpgradeCtaViewed: vi.fn(),
}));

import Extensions, { type Extension } from './index';
import {
	setUpdateExtensionsCount,
	useUpdateExtensionsCount,
} from './use-update-extensions-count';

const initialExtension: Extension = {
	slug: 'wcpos-example',
	name: 'Example extension',
	description: 'Example extension description.',
	version: '1.0.0',
	author: 'WCPOS',
	category: 'payments',
	tags: [],
	requires_wp: '6.0',
	requires_wc: '8.0',
	requires_wcpos: '1.9',
	requires_pro: true,
	icon: '',
	homepage: '',
	download_url: '',
	latest_version: '1.0.0',
	released_at: '2026-01-01T00:00:00Z',
	status: 'active',
	installed_version: '1.0.0',
};

interface ApiOptions {
	path: string;
	method: string;
}

function renderScreen() {
	const queryClient = new QueryClient({
		defaultOptions: { queries: { retry: false } },
	});

	return render(
		<QueryClientProvider client={queryClient}>
			<React.Suspense fallback="loading">
				<Extensions />
			</React.Suspense>
		</QueryClientProvider>
	);
}

function CountProbe() {
	const count = useUpdateExtensionsCount();
	return <span>Updates: {count ?? 'unknown'}</span>;
}

describe('Extensions refresh', () => {
	beforeEach(() => {
		apiFetchMock.mockReset();
		setUpdateExtensionsCount(0);
		(window as any).wcpos = {
			settings: {
				getComponent: () =>
					({ extension }: { extension: Extension }) => (
						<span>Action: {extension.status}</span>
					),
			},
		};
	});

	afterEach(() => {
		delete (window as any).wcpos;
	});

	it('shows loading state and replaces the extension query with refreshed versions', async () => {
		let resolveRefresh!: (extensions: Extension[]) => void;
		const refreshRequest = new Promise<Extension[]>((resolve) => {
			resolveRefresh = resolve;
		});

		apiFetchMock.mockImplementation((options: ApiOptions) => {
			if (options.method === 'POST') {
				return refreshRequest;
			}
			return Promise.resolve([initialExtension]);
		});

		const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
		render(
			<QueryClientProvider client={queryClient}>
				<React.Suspense fallback="loading">
					<Extensions />
					<CountProbe />
				</React.Suspense>
			</QueryClientProvider>
		);

		const refresh = await screen.findByRole('button', { name: 'Refresh versions' });
		fireEvent.click(refresh);

		await waitFor(() => expect(refresh).toBeDisabled());
		expect(apiFetchMock).toHaveBeenCalledWith({
			path: 'wcpos/v1/extensions/refresh?wcpos=1',
			method: 'POST',
		});

		await act(async () => {
			resolveRefresh([
				{
					...initialExtension,
					latest_version: '2.0.0',
					status: 'update_available',
					has_update: true,
				},
			]);
			await refreshRequest;
		});

		await waitFor(() => expect(screen.getByText('v2.0.0')).toBeInTheDocument());
		expect(screen.getByText('Update available')).toBeInTheDocument();
		expect(screen.getByText('Action: update_available')).toBeInTheDocument();
		expect(screen.getByText('Updates: 1')).toBeInTheDocument();
		expect(refresh).not.toBeDisabled();
	});

	it('keeps current versions and displays the server error when refresh fails', async () => {
		apiFetchMock.mockImplementation((options: ApiOptions) => {
			if (options.method === 'POST') {
				return Promise.reject(new Error('Catalog service unavailable'));
			}
			return Promise.resolve([initialExtension]);
		});

		renderScreen();

		fireEvent.click(await screen.findByRole('button', { name: 'Refresh versions' }));

		await waitFor(() => {
			expect(screen.getByText('Catalog service unavailable')).toBeInTheDocument();
		});
		expect(screen.getByRole('alert')).toHaveTextContent('Catalog service unavailable');
		expect(screen.getByText('v1.0.0')).toBeInTheDocument();
	});

	it('keeps refreshed versions when an older extension request resolves last', async () => {
		let resolveOlderGet!: (extensions: Extension[]) => void;
		const olderGetRequest = new Promise<Extension[]>((resolve) => {
			resolveOlderGet = resolve;
		});
		let resolveRefresh!: (extensions: Extension[]) => void;
		const refreshRequest = new Promise<Extension[]>((resolve) => {
			resolveRefresh = resolve;
		});
		let getRequests = 0;

		apiFetchMock.mockImplementation((options: ApiOptions) => {
			if (options.method === 'POST') {
				return refreshRequest;
			}

			getRequests += 1;
			return getRequests === 1 ? Promise.resolve([initialExtension]) : olderGetRequest;
		});

		const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
		render(
			<QueryClientProvider client={queryClient}>
				<React.Suspense fallback="loading">
					<Extensions />
					<CountProbe />
				</React.Suspense>
			</QueryClientProvider>
		);

		const refresh = await screen.findByRole('button', { name: 'Refresh versions' });
		const olderRefetch = queryClient.refetchQueries({ queryKey: ['extensions'] });
		await waitFor(() => expect(getRequests).toBe(2));

		fireEvent.click(refresh);
		await act(async () => {
			resolveRefresh([
				{
					...initialExtension,
					latest_version: '2.0.0',
					status: 'update_available',
					has_update: true,
				},
			]);
			await refreshRequest;
		});

		await waitFor(() => expect(screen.getByText('v2.0.0')).toBeInTheDocument());

		await act(async () => {
			resolveOlderGet([initialExtension]);
			await olderGetRequest;
			await olderRefetch;
		});

		await waitFor(() => {
			expect(queryClient.getQueryData<Extension[]>(['extensions'])?.[0].latest_version).toBe(
				'2.0.0'
			);
		});
		expect(screen.getByText('v2.0.0')).toBeInTheDocument();
		expect(screen.getByText('Update available')).toBeInTheDocument();
		expect(screen.getByText('Action: update_available')).toBeInTheDocument();
		expect(screen.getByText('Updates: 1')).toBeInTheDocument();
	});

	it('updates the shared count when navigation unmounts the screen during refresh', async () => {
		let resolveRefresh!: (extensions: Extension[]) => void;
		const refreshRequest = new Promise<Extension[]>((resolve) => {
			resolveRefresh = resolve;
		});

		apiFetchMock.mockImplementation((options: ApiOptions) => {
			if (options.method === 'POST') {
				return refreshRequest;
			}
			return Promise.resolve([initialExtension]);
		});

		const screenRender = renderScreen();
		fireEvent.click(await screen.findByRole('button', { name: 'Refresh versions' }));
		screenRender.unmount();

		await act(async () => {
			resolveRefresh([
				{
					...initialExtension,
					latest_version: '2.0.0',
					status: 'update_available',
					has_update: true,
				},
			]);
			await refreshRequest;
		});

		render(<CountProbe />);
		await waitFor(() => expect(screen.getByText('Updates: 1')).toBeInTheDocument());
	});
});
