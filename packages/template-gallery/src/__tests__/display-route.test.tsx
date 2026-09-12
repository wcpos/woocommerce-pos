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

describe('gallery search routes', () => {
	it.each(['/', '/?type=report', '/?type=receipt', '/?type=display', '/?type=closure'])(
		'loads the requested type and switches tabs from %s',
		async (entry) => {
			const paths: string[] = [];
			apiFetch.setFetchHandler((options) => {
				paths.push(options.path!);
				return Promise.resolve([]);
			});
			const router = createRouter({
				...galleryRouter.options,
				history: createMemoryHistory({ initialEntries: [entry] }),
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
				const type =
					entry === '/?type=closure'
						? 'closure'
						: entry === '/?type=display'
							? 'display'
							: 'receipt';
				expect(container.querySelector('h1')?.textContent).toBe(
					type === 'closure'
						? 'layout.closure_title'
						: type === 'display'
							? 'layout.display_title'
							: 'layout.title'
				);
				for (const path of ['wcpos/v1/templates', 'wcpos/v1/templates/gallery']) {
					expect(paths.some((value) => value.startsWith(`${path}?wcpos=1&type=${type}`))).toBe(
						true
					);
				}
				const display = Array.from(container.querySelectorAll('button')).find(
					(button) => button.textContent === 'tabs.display'
				);
				await act(async () => {
					display!.click();
				});
				expect(container.querySelector('h1')?.textContent).toBe('layout.display_title');
				expect(container.textContent).toContain('layout.display_description');
				expect(
					container.querySelector('a[href="https://docs.wcpos.com/customer-display"]')
				).not.toBeNull();
			} finally {
				act(() => root.unmount());
				queryClient.clear();
				vi.restoreAllMocks();
			}
		}
	);
});
