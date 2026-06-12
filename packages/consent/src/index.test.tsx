import '@testing-library/jest-dom/vitest';

import { cleanup, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

declare global {
	interface Window {
		wcpos?: {
			consent?: {
				restUrl: string;
				dismissUrl: string;
				nonce: string;
				showModal?: boolean;
				showCallout?: boolean;
				copy?: Record<string, string>;
			};
		};
	}
}

describe('consent bootstrap', () => {
	beforeEach(() => {
		vi.resetModules();
		document.body.innerHTML = '<div id="wcpos-consent-root"></div>';
	});

	afterEach(() => {
		cleanup();
		delete window.wcpos;
		document.body.innerHTML = '';
	});

	it('renders copy overrides from the inline config', async () => {
		window.wcpos = {
			consent: {
				restUrl: '/wp-json/wcpos/v1/consent',
				dismissUrl: '/wp-json/wcpos/v1/consent/dismiss',
				nonce: 'nonce',
				showModal: true,
				showCallout: true,
				copy: {
					title: 'Try private analytics?',
					body: 'Share anonymous setup data with WCPOS?',
					allow_label: 'Share data',
					deny_label: 'Keep private',
				},
			},
		};

		await act(async () => {
			await import('./index');
		});

		expect(await screen.findAllByText('Try private analytics?')).toHaveLength(2);
		expect(screen.getAllByText('Share anonymous setup data with WCPOS?')).toHaveLength(2);
		expect(screen.getAllByText('Share data')).toHaveLength(2);
		expect(screen.getAllByText('Keep private')).toHaveLength(2);
	}, 10000);
});
