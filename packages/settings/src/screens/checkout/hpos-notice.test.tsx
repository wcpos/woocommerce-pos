import * as React from 'react';

import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { HposNotice } from './hpos-notice';

const SETTINGS_URL =
	'https://example.test/wp-admin/admin.php?page=wc-settings&tab=advanced&section=features';

describe('HposNotice', () => {
	beforeEach(() => {
		window.wcpos = { settings: {} };
	});
	afterEach(() => {
		delete window.wcpos;
	});

	it('renders nothing when order_storage is absent', () => {
		window.wcpos = { settings: { environment: {} } };
		const { container } = render(<HposNotice />);
		expect(container).toBeEmptyDOMElement();
	});

	it('renders nothing when the store uses HPOS', () => {
		window.wcpos = {
			settings: { environment: { order_storage: { hpos: true, settings_url: SETTINGS_URL } } },
		};
		const { container } = render(<HposNotice />);
		expect(container).toBeEmptyDOMElement();
	});

	it('shows the recommendation with both links on legacy order storage', () => {
		window.wcpos = {
			settings: { environment: { order_storage: { hpos: false, settings_url: SETTINGS_URL } } },
		};
		render(<HposNotice />);
		expect(screen.getByTestId('hpos-notice')).toBeInTheDocument();
		expect(screen.getByText(/this store uses legacy order storage/)).toBeInTheDocument();

		const settingsLink = screen.getByRole('link', {
			name: "Open WooCommerce's order storage setting",
		});
		expect(settingsLink).toHaveAttribute('href', SETTINGS_URL);
		expect(settingsLink).toHaveAttribute('target', '_blank');
		expect(settingsLink).toHaveAttribute('rel', 'noreferrer');

		const docsLink = screen.getByRole('link', { name: 'How to switch' });
		expect(docsLink).toHaveAttribute(
			'href',
			'https://docs.wcpos.com/support/performance/order-storage'
		);
		expect(docsLink).toHaveAttribute('target', '_blank');
		expect(docsLink).toHaveAttribute('rel', 'noreferrer');
	});

	it('has no dismiss control', () => {
		window.wcpos = {
			settings: { environment: { order_storage: { hpos: false, settings_url: SETTINGS_URL } } },
		};
		render(<HposNotice />);
		expect(screen.queryByRole('button')).not.toBeInTheDocument();
	});
});
