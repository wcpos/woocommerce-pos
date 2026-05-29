import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';

import { SnackbarProvider } from '@wcpos/ui';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

import CloudPrint from './index';

interface ApiOpts {
	path: string;
	method?: string;
	data?: unknown;
}

function routeApiFetch({
	getSettings,
	templates = [],
}: {
	getSettings: () => unknown;
	templates?: unknown[];
}) {
	apiFetchMock.mockImplementation((opts: ApiOpts) => {
		if (opts.path.includes('/templates')) {
			return Promise.resolve(templates);
		}
		if (opts.path.includes('/settings/cloud-print')) {
			return Promise.resolve(getSettings());
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
					<CloudPrint />
				</React.Suspense>
			</SnackbarProvider>
		</QueryClientProvider>
	);
}

beforeEach(() => {
	apiFetchMock.mockReset();
	(window as unknown as { wpApiSettings?: { root?: string } }).wpApiSettings = {
		root: 'https://mystore.com/wp-json/',
	};
});

afterEach(() => {
	delete (window as unknown as { wpApiSettings?: unknown }).wpApiSettings;
});

describe('CloudPrint screen', () => {
	it('renders the intro callout, printer section with a card, and the rules section', async () => {
		routeApiFetch({
			getSettings: () => ({
				printers: [
					{ id: 'kitchen', name: 'Kitchen', provider: 'star-cloudprnt', store_id: 0 },
				],
				assignments: [],
			}),
			templates: [{ id: 1, title: 'Receipt', status: 'publish', is_active: true, engine: 'thermal' }],
		});

		renderScreen();

		// Intro Callout.
		expect(await screen.findByText('What is cloud printing?')).toBeTruthy();
		// "Your cloud printers" section heading.
		expect(screen.getByText('Your cloud printers')).toBeTruthy();
		// A card for the seeded printer.
		expect(screen.getByTestId('printer-card-kitchen')).toBeTruthy();
		// "+ Add a printer" button.
		expect(screen.getByTestId('cloud-print-add')).toBeTruthy();
		// Auto-print rules section heading.
		expect(screen.getByText('Auto-print rules')).toBeTruthy();
	});

	it('shows the empty state when there are no printers', async () => {
		routeApiFetch({
			getSettings: () => ({ printers: [], assignments: [] }),
		});

		renderScreen();

		expect(await screen.findByTestId('cloud-print-empty')).toBeTruthy();
	});
});
