import * as React from 'react';

import { act, fireEvent, render, screen } from '@testing-library/react';
import { addQueryArgs } from '@wordpress/url';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ExportClosures from './index';
import useNotices, { NoticesProvider } from '../../hooks/use-notices';

vi.mock('../../translations', () => ({ t: (_key: string, fallback: string) => fallback }));
vi.mock('@wordpress/url', () => ({ addQueryArgs: vi.fn(() => '#export-download') }));

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

beforeEach(() => {
	vi.clearAllMocks();
	window.location.hash = '';
});

afterEach(() => {
	delete window.wpApiSettings;
	window.location.hash = '';
});

describe('Export closures', () => {
	it.each(['https://example.com/wp-json/', 'https://example.com/?rest_route=/'])(
		'navigates to the download with the admin marker and nonce from %s',
		(root) => {
			window.wpApiSettings = { root, nonce: 'test-nonce' };
			renderScreen();

			fireEvent.click(screen.getByTestId('export-closures-download'));

			expect(addQueryArgs).toHaveBeenCalledWith(`${root}wcpos/v2/closures/export`, {
				wcpos: 1,
				_wpnonce: 'test-nonce',
			});
			expect(window.location.hash).toBe('#export-download');
			expect(screen.getByTestId('export-closures-download')).toBeDisabled();
			expect(screen.getByTestId('export-closures-download')).toHaveTextContent('Downloading…');
		}
	);

	/**
	 * An attachment response does not navigate and fires no observable event, so a
	 * latched pending state would strand the button on "Downloading…" until the
	 * merchant reloaded the screen — they could not export twice in one visit.
	 */
	it('releases the button after the acknowledgement window so a second export is possible', () => {
		vi.useFakeTimers();
		try {
			window.wpApiSettings = { root: 'https://example.com/wp-json/', nonce: 'test-nonce' };
			renderScreen();

			fireEvent.click(screen.getByTestId('export-closures-download'));
			expect(screen.getByTestId('export-closures-download')).toBeDisabled();

			act(() => {
				vi.advanceTimersByTime(3000);
			});

			expect(screen.getByTestId('export-closures-download')).not.toBeDisabled();
			expect(screen.getByTestId('export-closures-download')).toHaveTextContent('Download CSV');
		} finally {
			vi.useRealTimers();
		}
	});

	it.each([undefined, {}, { root: 'https://example.com/wp-json/' }])(
		'reports missing download configuration without disabling the button',
		(settings) => {
			window.wpApiSettings = settings;
			renderScreen();

			fireEvent.click(screen.getByTestId('export-closures-download'));

			expect(screen.getByRole('alert')).toHaveTextContent(
				'The download could not be started. Reload this page and try again.'
			);
			expect(addQueryArgs).not.toHaveBeenCalled();
			expect(screen.getByTestId('export-closures-download')).not.toBeDisabled();
		}
	);
});
