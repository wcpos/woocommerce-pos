import * as React from 'react';

import { render, screen, act, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
	AccessSkeleton,
	CardGridSkeleton,
	FormSkeleton,
	LicenseSkeleton,
	ListSkeleton,
} from './components/skeleton';

// Every request stays pending, so each screen is held in its loading state.
vi.mock('@wordpress/api-fetch', () => ({ default: () => new Promise(() => {}) }));
vi.mock('./lib/analytics', () => ({
	captureSettingsSectionViewed: vi.fn(),
	captureUpgradeCtaClicked: vi.fn(),
	captureUpgradeCtaViewed: vi.fn(),
}));

/** Renders the real settings app at `path`, with fresh router and query cache. */
async function renderAppAt(path: string) {
	window.location.hash = `#${path}`;
	vi.resetModules();
	const { QueryClientProvider } = await import('@tanstack/react-query');
	const { RouterProvider } = await import('@tanstack/react-router');
	const { SnackbarProvider } = await import('@wcpos/ui');
	const { NoticesProvider } = await import('./hooks/use-notices');
	const { queryClient } = await import('./query-client');
	const { router } = await import('./router');

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
}

/** The markup of `skeleton` rendered on its own, to compare with what the app shows. */
function markupOf(skeleton: React.ReactElement) {
	const { container, unmount } = render(skeleton);
	const html = container.innerHTML;
	unmount();
	return html;
}

afterEach(cleanup);

// The screens whose content area was blank while loading. General and Sessions have
// their own Suspense boundaries, and Export closures loads nothing, so they are not here.
describe('settings screens while their data loads', () => {
	it.each([
		['/checkout', <FormSkeleton rows={4} />],
		['/cloud-print', <FormSkeleton rows={4} />],
		['/access', <AccessSkeleton />],
		['/logs', <ListSkeleton rows={8} />],
		['/license', <LicenseSkeleton />],
		['/extensions', <CardGridSkeleton cards={4} />],
	])('%s shows its loading skeleton', async (path, skeleton) => {
		const expected = markupOf(skeleton);

		await renderAppAt(path);

		const status = screen.queryByRole('status', { name: 'Loading…' });
		expect(status?.outerHTML).toBe(expected);
	});
});
