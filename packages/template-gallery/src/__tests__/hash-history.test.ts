import { expect, it, vi } from 'vitest';

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn(() => Promise.resolve([])) }));
vi.mock('../translations', () => ({ t: (key: string) => key }));

it('loads Display from the hash and preserves the WordPress admin query when navigating', async () => {
	const originalUrl = window.location.href;
	window.history.replaceState(
		null,
		'',
		'/wp-admin/admin.php?page=wcpos-templates#/?type=display'
	);
	const { router } = await import('../router');
	const { queryClient } = await import('../query-client');
	try {
		await router.load();
		expect(router.state.location.search).toEqual({ type: 'display' });
		expect(router.state.matches.at(-1)?.search).toEqual({ type: 'display' });
		await router.navigate({ to: '/', search: { type: 'receipt' } });
		expect(router.state.location.search).toEqual({ type: 'receipt' });
		expect(window.location.hash).toBe('#/?type=receipt');
		expect(window.location.search).toBe('?page=wcpos-templates');
		expect(window.location.pathname).toBe('/wp-admin/admin.php');
	} finally {
		router.history.destroy();
		queryClient.clear();
		window.history.replaceState(null, '', originalUrl);
	}
});
