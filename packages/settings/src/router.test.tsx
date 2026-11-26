import * as React from 'react';

import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { SnackbarProvider } from '@wcpos/ui';

import { NoticesProvider } from './hooks/use-notices';
import { queryClient } from './query-client';
import { router } from './router';

vi.hoisted(() => {
	window.location.hash = '#/extensions';
});

const resolvers: Record<string, (value: unknown) => void> = {};
vi.mock('@wordpress/api-fetch', () => ({
	default: ({ path }: { path: string }) =>
		new Promise((resolve) => {
			resolvers[path.split('?')[0]] = resolve;
		}),
}));
vi.mock('./lib/analytics', () => ({
	captureSettingsSectionViewed: vi.fn(),
	captureUpgradeCtaClicked: vi.fn(),
	captureUpgradeCtaViewed: vi.fn(),
}));

describe('Extensions route', () => {
	it('shows the extensions skeleton while the extensions load, then the list', async () => {
		render(
			<QueryClientProvider client={queryClient}>
				<NoticesProvider>
					<SnackbarProvider>
						<RouterProvider router={router} />
					</SnackbarProvider>
				</NoticesProvider>
			</QueryClientProvider>
		);
		await act(async () => {});

		expect(screen.getByRole('status', { name: 'Loading…' })).toBeInTheDocument();

		await act(async () => resolvers['wcpos/v2/extensions']([]));
		await act(async () => resolvers['wcpos/v2/settings/payment-gateways']({ gateways: {} }));

		expect(await screen.findByText('No extensions found.')).toBeInTheDocument();
		expect(screen.queryByRole('status', { name: 'Loading…' })).not.toBeInTheDocument();
	});
});
