import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';

import { SnackbarProvider } from '@wcpos/ui';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

// The analytics helpers reach for window.wcpos.posthog; stub them out so the
// activation flow under test does not depend on PostHog being injected.
vi.mock('../../lib/analytics', () => ({
	captureLicenseActivationAttempted: vi.fn(),
	captureLicenseActivationFailed: vi.fn(),
	captureLicenseActivationSucceeded: vi.fn(),
	captureUpgradeCtaClicked: vi.fn(),
	captureUpgradeCtaViewed: vi.fn(),
}));

import License from './index';

interface ApiOpts {
	path: string;
	method?: string;
	data?: unknown;
}

const fetchMock = vi.fn();

function routeSettings(settings: Record<string, unknown>) {
	apiFetchMock.mockImplementation((opts: ApiOpts) => {
		if (opts.path.includes('/settings/license')) {
			return Promise.resolve(settings);
		}
		return Promise.resolve({});
	});
}

function renderScreen() {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return render(
		<QueryClientProvider client={client}>
			<SnackbarProvider>
				<React.Suspense fallback="loading">
					<License />
				</React.Suspense>
			</SnackbarProvider>
		</QueryClientProvider>
	);
}

beforeEach(() => {
	apiFetchMock.mockReset();
	fetchMock.mockReset();
	fetchMock.mockResolvedValue({
		json: () => Promise.resolve({ success: true, activated: true, license_tier: 'pro' }),
	});
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	vi.unstubAllGlobals();
	delete window.wcpos;
});

function activatedRequestUrl(): URL {
	expect(fetchMock).toHaveBeenCalledTimes(1);
	const [calledUrl] = fetchMock.mock.calls[0] as [string];
	return new URL(calledUrl);
}

describe('License activation identity join', () => {
	it('carries anon_id and site_uuid from window.wcpos.settings on activation', async () => {
		window.wcpos = {
			settings: {
				anon_id: '11111111-1111-4111-8111-111111111111',
				site_uuid: '22222222-2222-4222-8222-222222222222',
			},
		};
		routeSettings({
			instance: 'inst-123',
			product_id: '42',
			platform: 'wordpress',
			version: '1.9.0',
			activated: false,
		});

		renderScreen();

		const input = await screen.findByRole('textbox');
		fireEvent.change(input, { target: { value: 'ABC-KEY' } });
		fireEvent.click(screen.getByRole('button', { name: /activate/i }));

		await waitFor(() => expect(fetchMock).toHaveBeenCalled());

		const url = activatedRequestUrl();
		expect(url.searchParams.get('request')).toBe('activation');
		expect(url.searchParams.get('anon_id')).toBe('11111111-1111-4111-8111-111111111111');
		expect(url.searchParams.get('site_uuid')).toBe('22222222-2222-4222-8222-222222222222');
	});

	it('omits identity args when window.wcpos.settings is absent (older build)', async () => {
		routeSettings({
			instance: 'inst-123',
			product_id: '42',
			platform: 'wordpress',
			version: '1.9.0',
			activated: false,
		});

		renderScreen();

		const input = await screen.findByRole('textbox');
		fireEvent.change(input, { target: { value: 'ABC-KEY' } });
		fireEvent.click(screen.getByRole('button', { name: /activate/i }));

		await waitFor(() => expect(fetchMock).toHaveBeenCalled());

		const url = activatedRequestUrl();
		expect(url.searchParams.has('anon_id')).toBe(false);
		expect(url.searchParams.has('site_uuid')).toBe(false);
	});

	it('does not send identity args on deactivation', async () => {
		window.wcpos = {
			settings: {
				anon_id: '11111111-1111-4111-8111-111111111111',
				site_uuid: '22222222-2222-4222-8222-222222222222',
			},
		};
		routeSettings({
			instance: 'inst-123',
			product_id: '42',
			platform: 'wordpress',
			version: '1.9.0',
			key: 'ABC-KEY-ALREADY-ACTIVE',
			activated: true,
		});

		renderScreen();

		const deactivate = await screen.findByRole('button', { name: /deactivate/i });
		fireEvent.click(deactivate);

		await waitFor(() => expect(fetchMock).toHaveBeenCalled());

		const url = activatedRequestUrl();
		expect(url.searchParams.get('request')).toBe('deactivation');
		expect(url.searchParams.has('anon_id')).toBe(false);
		expect(url.searchParams.has('site_uuid')).toBe(false);
	});
});
