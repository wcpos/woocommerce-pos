import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import useSettingsApi from './use-settings-api';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));
vi.mock('@wcpos/ui', () => ({
	useSnackbar: () => ({ addSnackbar: vi.fn(), removeSnackbar: vi.fn() }),
}));

function wrapper({ children }: { children: React.ReactNode }) {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return (
		<QueryClientProvider client={client}>
			<React.Suspense fallback="loading">{children}</React.Suspense>
		</QueryClientProvider>
	);
}

describe('useSettingsApi', () => {
	beforeEach(() => {
		apiFetchMock.mockReset();
	});

	it('test_overlapping_saves_failed_rollback_keeps_the_other_save', async () => {
		// Arrange: two cards on the payment-gateways settings, both off.
		let rejectA: (err: Error) => void = () => {};
		apiFetchMock.mockImplementation(({ method, data }) => {
			if (method === 'GET') {
				return Promise.resolve({ gateways: { a: { enabled: false }, b: { enabled: false } } });
			}
			if (data.gateways.a) {
				return new Promise((_resolve, reject) => {
					rejectA = reject;
				});
			}
			return Promise.resolve({ gateways: { a: { enabled: false }, b: { enabled: true } } });
		});
		const { result } = renderHook(
			() => ({
				a: useSettingsApi('payment-gateways', { quietErrors: true }),
				b: useSettingsApi('payment-gateways', { quietErrors: true }),
			}),
			{ wrapper }
		);
		await waitFor(() => expect(result.current).toBeTruthy());

		// Act: turn A on, then B on while A's save is pending; B saves, then A fails.
		const onErrorA = vi.fn();
		const onSuccessB = vi.fn();
		act(() =>
			result.current.a.mutate({ gateways: { a: { enabled: true } } }, { onError: onErrorA })
		);
		act(() =>
			result.current.b.mutate({ gateways: { b: { enabled: true } } }, { onSuccess: onSuccessB })
		);
		await waitFor(() => expect(onSuccessB).toHaveBeenCalled());
		await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(3));
		act(() => rejectA(new Error('Save failed')));
		await waitFor(() => expect(onErrorA).toHaveBeenCalled());

		// Assert: A is rolled back, B stays as the server has it.
		expect(result.current.b.data.gateways).toEqual({
			a: { enabled: false },
			b: { enabled: true },
		});
	});
});
