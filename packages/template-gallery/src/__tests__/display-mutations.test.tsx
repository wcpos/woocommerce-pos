import { act } from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import apiFetch, { type APIFetchOptions } from '@wordpress/api-fetch';
import { createRoot } from 'react-dom/client';
import { describe, expect, it, vi } from 'vitest';

import { useInstallGalleryTemplate } from '../hooks/use-gallery-templates';
import { useSetActiveTemplate } from '../hooks/use-templates';

const { addSnackbar } = vi.hoisted(() => ({ addSnackbar: vi.fn() }));
vi.mock('@wcpos/ui', () => ({ useSnackbar: () => ({ addSnackbar }) }));
vi.mock('../translations', () => ({ t: (key: string) => key }));

describe('display mutations', () => {
	it.each(['live', 'install', 'error'] as const)('handles the %s mutation', async (action) => {
		const requests: APIFetchOptions[] = [];
		apiFetch.setFetchHandler((options) => {
			requests.push(options);
			return action === 'error' ? Promise.reject(new Error('failed')) : Promise.resolve({});
		});
		const client = new QueryClient();
		const invalidate = vi.spyOn(client, 'invalidateQueries');
		let submit: () => Promise<unknown>;
		function Harness() {
			const live = useSetActiveTemplate('display');
			const install = useInstallGalleryTemplate('display');
			submit = () =>
				action === 'install' ? install.mutateAsync('display') : live.mutateAsync(123);
			return null;
		}
		const root = createRoot(document.createElement('div'));
		try {
			act(() =>
				root.render(
					<QueryClientProvider client={client}>
						<Harness />
					</QueryClientProvider>
				)
			);
			await act(async () => {
				await submit().catch(() => {});
			});
			const target = new URL(requests[0].path!, 'https://example.test');
			expect(target.pathname).toBe(
				action === 'install' ? '/wcpos/v1/templates/install' : '/wcpos/v1/templates/batch'
			);
			expect(target.searchParams.get('wcpos')).toBe('1');
			expect(requests[0].method).toBe('POST');
			expect(requests[0].data).toEqual(
				action === 'install' ? { gallery_key: 'display' } : { type: 'display', active: 123 }
			);
			expect(addSnackbar).toHaveBeenLastCalledWith({
				message:
					action === 'error'
						? 'snackbar.update_failed'
						: action === 'install'
							? 'snackbar.display_installed'
							: 'snackbar.display_live',
				status: action === 'error' ? 'error' : 'success',
			});
			if (action !== 'error') expect(invalidate).toHaveBeenCalledWith({ queryKey: ['templates'] });
		} finally {
			act(() => root.unmount());
			client.clear();
			vi.clearAllMocks();
		}
	});
});
