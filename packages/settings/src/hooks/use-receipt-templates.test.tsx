import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

import { useReceiptTemplateOptions } from './use-receipt-templates';

function wrapper({ children }: { children: React.ReactNode }) {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return (
		<QueryClientProvider client={client}>
			<React.Suspense fallback="loading">{children}</React.Suspense>
		</QueryClientProvider>
	);
}

describe('useReceiptTemplateOptions', () => {
	beforeEach(() => {
		apiFetchMock.mockReset();
	});

	it('returns published/active receipt templates as { value, label, engine } options and excludes inactive drafts', async () => {
		// Arrange
		apiFetchMock.mockResolvedValue([
			{ id: 12, title: 'Standard Receipt', status: 'publish', is_active: false, engine: 'logicless' },
			{ id: 'plugin-core', title: 'Core Receipt', status: 'publish', is_active: true, engine: 'legacy-php' },
			{ id: 99, title: 'Draft Receipt', status: 'draft', is_active: false, engine: 'thermal' },
		]);

		// Act
		const { result } = renderHook(() => useReceiptTemplateOptions(), { wrapper });

		// Assert
		await waitFor(() => expect(result.current).toBeTruthy());
		expect(apiFetchMock).toHaveBeenCalledWith({
			path: 'wcpos/v1/templates?wcpos=1&type=receipt',
			method: 'GET',
		});
		expect(result.current).toEqual([
			{ value: '12', label: 'Standard Receipt', engine: 'logicless' },
			{ value: 'plugin-core', label: 'Core Receipt', engine: 'legacy-php' },
		]);
	});

	it('keeps an active draft template', async () => {
		// Arrange
		apiFetchMock.mockResolvedValue([
			{ id: 7, title: 'Active Draft', status: 'draft', is_active: true, engine: 'logicless' },
		]);

		// Act
		const { result } = renderHook(() => useReceiptTemplateOptions(), { wrapper });

		// Assert
		await waitFor(() => expect(result.current).toBeTruthy());
		expect(result.current).toEqual([{ value: '7', label: 'Active Draft', engine: 'logicless' }]);
	});
});
