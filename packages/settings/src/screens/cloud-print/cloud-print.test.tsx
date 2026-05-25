import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@wordpress/api-fetch', () => ({
	default: vi.fn(async () => ({
		printers: [{ id: 'kitchen', name: 'Kitchen', protocol: 'star-cloudprnt', store_id: 0 }],
		assignments: [],
	})),
}));

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

describe('CloudPrint screen', () => {
	it('lists registered cloud printers', async () => {
		renderScreen();
		expect(await screen.findByText('Kitchen')).toBeTruthy();
	});
});
