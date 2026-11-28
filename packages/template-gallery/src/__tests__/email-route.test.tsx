import { act } from 'react';

import { QueryClientProvider } from '@tanstack/react-query';
import { createMemoryHistory, createRouter, RouterProvider } from '@tanstack/react-router';
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from 'react-dom/client';
import { describe, expect, it, vi } from 'vitest';

import { queryClient } from '../query-client';
import { router as galleryRouter } from '../router';

vi.mock('../translations', () => ({ t: (key: string) => key }));
vi.mock('@wcpos/ui', async (importOriginal) => ({
	...(await importOriginal<typeof import('@wcpos/ui')>()),
	useSnackbar: () => ({ addSnackbar: vi.fn() }),
}));

describe('email designs route', () => {
	it.each([false, true])('saves designs and the default (unavailable: %s)', async (unavailable) => {
		const designs = [
			{ id: 13, title: 'Market design', status: 'publish' },
			{ id: 27, title: 'New look', status: 'draft' },
		];
		const endpoint = 'wcpos/v2/templates/customer-email-design?wcpos=1';
		let templateId = 0;
		let unavailableDesign = unavailable;
		const fetch = vi.fn<Parameters<typeof apiFetch.setFetchHandler>[0]>((options) => {
			if (!options.path?.startsWith(endpoint)) return Promise.resolve([]);
			if (options.method === 'POST') {
				templateId = (options.data as { template_id: number }).template_id;
				unavailableDesign = false;
			}
			return Promise.resolve({
				template_id: templateId,
				unavailable_design: unavailableDesign,
				designs,
			});
		});
		apiFetch.setFetchHandler(fetch);
		const router = createRouter({
			...galleryRouter.options,
			history: createMemoryHistory({ initialEntries: ['/?type=email'] }),
		});
		vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
		const container = document.createElement('div');
		const root = createRoot(container);

		try {
			await act(async () => {
				await router.load();
				root.render(
					<QueryClientProvider client={queryClient}>
						<RouterProvider router={router} />
					</QueryClientProvider>
				);
			});
			await vi.waitFor(() => {
				expect(container.textContent).toContain('Market design');
			});
			expect(container.querySelector('h1')?.textContent).toBe('layout.email_title');
			expect(fetch).toHaveBeenCalledTimes(1);
			expect(fetch).toHaveBeenCalledWith(
				expect.objectContaining({ path: expect.stringContaining(endpoint) })
			);
			expect(
				container.querySelector('a[href$="post_type=wcpos_template&wcpos_type=email"]')
			).not.toBeNull();
			expect(container.querySelector('a[href$="post=13&action=edit"]')).not.toBeNull();
			expect(container.querySelector('a[href$="post=27&action=edit"]')).not.toBeNull();

			const select = container.querySelector('select')!;
			expect(Array.from(select.options, (option) => option.value)).toEqual(['0', '13']);
			expect(select.value).toBe('0');

			const form = container.querySelector('form')!;
			expect(form.querySelector('button')?.disabled).toBe(!unavailable);
			expect(container.textContent?.includes('email.previous_design_unavailable')).toBe(
				unavailable
			);

			for (const id of unavailable ? [0, 13, 0] : [13, 0]) {
				await act(async () => {
					select.value = String(id);
					select.dispatchEvent(new Event('change', { bubbles: true }));
				});
				await act(async () => {
					form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
				});
				await vi.waitFor(() => {
					expect(fetch).toHaveBeenLastCalledWith(
						expect.objectContaining({ method: 'POST', data: { template_id: id } })
					);
					expect(templateId).toBe(id);
					expect(container.textContent).not.toContain('email.previous_design_unavailable');
					expect(form.querySelector('button')?.disabled).toBe(true);
				});
			}
		} finally {
			act(() => root.unmount());
			queryClient.clear();
			vi.restoreAllMocks();
		}
	});

	it('keeps a saved design when an older refresh returns after the save', async () => {
		const designs = [{ id: 13, title: 'Market design', status: 'publish' }];
		const original = { template_id: 0, designs };
		let finishRefresh!: (settings: typeof original) => void;
		const refresh = new Promise<typeof original>((resolve) => {
			finishRefresh = resolve;
		});
		let refreshStarted = false;
		let refreshSignal: AbortSignal | null | undefined;
		const fetch = vi.fn<Parameters<typeof apiFetch.setFetchHandler>[0]>((options) => {
			if (options.method === 'POST') return Promise.resolve({ template_id: 13, designs });
			if (!refreshStarted) {
				refreshStarted = true;
				return Promise.resolve(original);
			}
			refreshSignal = options.signal;
			return refresh;
		});
		apiFetch.setFetchHandler(fetch);
		const router = createRouter({
			...galleryRouter.options,
			history: createMemoryHistory({ initialEntries: ['/?type=email'] }),
		});
		vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
		const container = document.createElement('div');
		const root = createRoot(container);

		try {
			await act(async () => {
				await router.load();
				root.render(
					<QueryClientProvider client={queryClient}>
						<RouterProvider router={router} />
					</QueryClientProvider>
				);
			});
			await vi.waitFor(() => {
				expect(container.querySelector('select')?.value).toBe('0');
			});
			const pendingRefresh = queryClient.invalidateQueries();
			await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));

			const select = container.querySelector('select')!;
			const form = container.querySelector('form')!;
			await act(async () => {
				select.value = '13';
				select.dispatchEvent(new Event('change', { bubbles: true }));
			});
			await act(async () => {
				form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
			});
			await vi.waitFor(() => {
				expect(container.textContent).toContain('email.saved');
			});

			await act(async () => {
				// The transport may still finish even after its abort signal fires.
				finishRefresh(original);
				await pendingRefresh;
				await new Promise((resolve) => setTimeout(resolve, 0));
			});
			expect(select.value).toBe('13');
			expect(refreshSignal?.aborted).toBe(true);
		} finally {
			act(() => root.unmount());
			queryClient.clear();
			vi.restoreAllMocks();
		}
	});
});
