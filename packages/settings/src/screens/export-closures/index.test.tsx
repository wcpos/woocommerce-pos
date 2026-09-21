import * as React from 'react';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { addQueryArgs } from '@wordpress/url';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ExportClosures from './index';
import useNotices, { NoticesProvider } from '../../hooks/use-notices';

vi.mock('../../translations', () => ({ t: (_key: string, fallback: string) => fallback }));
vi.mock('@wordpress/url', () => ({ addQueryArgs: vi.fn(() => 'https://example.com/export') }));

function SharedNotice() {
	const { notice } = useNotices();
	return notice ? <div role="alert">{notice.message}</div> : null;
}

function renderScreen() {
	return render(
		<NoticesProvider>
			<SharedNotice />
			<ExportClosures />
		</NoticesProvider>
	);
}

/** A successful export: a CSV body with the server's filename. */
function csvResponse() {
	return {
		ok: true,
		blob: async () => new Blob(['closure_number\n1\n'], { type: 'text/csv' }),
		headers: {
			get: (name: string) =>
				name.toLowerCase() === 'content-disposition'
					? 'attachment; filename="wcpos-closures-shop-2026-09-21.csv"'
					: null,
		},
	};
}

let clicked: HTMLAnchorElement[];

beforeEach(() => {
	vi.clearAllMocks();
	clicked = [];
	// jsdom implements neither of these.
	vi.stubGlobal('URL', {
		...URL,
		createObjectURL: vi.fn(() => 'blob:mock'),
		revokeObjectURL: vi.fn(),
	});
	vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
		this: HTMLAnchorElement
	) {
		clicked.push(this);
	});
});

afterEach(() => {
	delete window.wpApiSettings;
	vi.unstubAllGlobals();
	vi.restoreAllMocks();
});

describe('Export closures', () => {
	it.each(['https://example.com/wp-json/', 'https://example.com/?rest_route=/'])(
		'downloads the CSV with the admin marker and nonce from %s',
		async (root) => {
			window.wpApiSettings = { root, nonce: 'test-nonce' };
			const fetchMock = vi.fn(async () => csvResponse());
			vi.stubGlobal('fetch', fetchMock);
			renderScreen();

			fireEvent.click(screen.getByTestId('export-closures-download'));

			await waitFor(() => expect(clicked).toHaveLength(1));
			expect(addQueryArgs).toHaveBeenCalledWith(`${root}wcpos/v2/closures/export`, {
				wcpos: 1,
				_wpnonce: 'test-nonce',
			});
			// Cookie auth must ride along, or the nonce alone will not authenticate.
			expect(fetchMock).toHaveBeenCalledWith('https://example.com/export', {
				credentials: 'same-origin',
			});
			// The server names the file; the client does not invent one.
			expect(clicked[0].download).toBe('wcpos-closures-shop-2026-09-21.csv');
			expect(screen.queryByRole('alert')).toBeNull();
		}
	);

	/**
	 * The reason this screen fetches rather than navigating. Only a successful export
	 * carries Content-Disposition, so a top-level navigation to a refusal would replace
	 * the Settings screen with raw JSON and lose the merchant's place.
	 */
	it.each([401, 403])('sends a %s to the permissions message', async (status) => {
		window.wpApiSettings = { root: 'https://example.com/wp-json/', nonce: 'test-nonce' };
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => ({ ok: false, status }))
		);
		renderScreen();

		fireEvent.click(screen.getByTestId('export-closures-download'));

		await waitFor(() =>
			expect(screen.getByRole('alert')).toHaveTextContent('permission to view reports')
		);
		// Nothing was downloaded, and the screen is still usable.
		expect(clicked).toHaveLength(0);
		expect(screen.getByTestId('export-closures-download')).not.toBeDisabled();
	});

	/**
	 * A 5xx is not a permissions problem. Telling a correctly authorized administrator
	 * to check their report permissions sends them to fix something that is not broken.
	 */
	it.each([500, 503])('sends a %s to the server-failure message', async (status) => {
		window.wpApiSettings = { root: 'https://example.com/wp-json/', nonce: 'test-nonce' };
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => ({ ok: false, status }))
		);
		renderScreen();

		fireEvent.click(screen.getByTestId('export-closures-download'));

		await waitFor(() =>
			expect(screen.getByRole('alert')).toHaveTextContent('problem on the server')
		);
		expect(screen.getByRole('alert')).not.toHaveTextContent('permission to view reports');
		expect(clicked).toHaveLength(0);
	});

	it('reports a transport failure without stranding the button', async () => {
		window.wpApiSettings = { root: 'https://example.com/wp-json/', nonce: 'test-nonce' };
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => {
				throw new Error('offline');
			})
		);
		renderScreen();

		fireEvent.click(screen.getByTestId('export-closures-download'));

		await waitFor(() =>
			expect(screen.getByRole('alert')).toHaveTextContent('The download could not be started')
		);
		expect(screen.getByTestId('export-closures-download')).not.toBeDisabled();
	});

	/** A second export must be possible without reloading the screen. */
	it('releases the button once the download completes', async () => {
		window.wpApiSettings = { root: 'https://example.com/wp-json/', nonce: 'test-nonce' };
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => csvResponse())
		);
		renderScreen();

		fireEvent.click(screen.getByTestId('export-closures-download'));

		await waitFor(() => expect(clicked).toHaveLength(1));
		await waitFor(() => expect(screen.getByTestId('export-closures-download')).not.toBeDisabled());
		expect(screen.getByTestId('export-closures-download')).toHaveTextContent('Download CSV');
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock');
	});

	it.each([undefined, {}, { root: 'https://example.com/wp-json/' }])(
		'reports missing download configuration without fetching',
		async (settings) => {
			window.wpApiSettings = settings;
			const fetchMock = vi.fn();
			vi.stubGlobal('fetch', fetchMock);
			renderScreen();

			fireEvent.click(screen.getByTestId('export-closures-download'));

			expect(screen.getByRole('alert')).toHaveTextContent(
				'The download could not be started. Reload this page and try again.'
			);
			expect(fetchMock).not.toHaveBeenCalled();
			expect(screen.getByTestId('export-closures-download')).not.toBeDisabled();
		}
	);
});
