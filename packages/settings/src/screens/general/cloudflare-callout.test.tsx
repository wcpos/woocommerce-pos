import * as React from 'react';

import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { CloudflareCallout } from './cloudflare-callout';

describe('CloudflareCallout', () => {
	beforeEach(() => {
		window.wcpos = { settings: {} };
	});
	afterEach(() => {
		delete window.wcpos;
	});

	it('renders nothing when environment is undefined', () => {
		const { container } = render(<CloudflareCallout />);
		expect(container).toBeEmptyDOMElement();
	});

	it('renders nothing when the request is not proxied, even with the plugin active', () => {
		window.wcpos = {
			settings: { environment: { cloudflare: { proxied: false, plugin_active: true } } },
		};
		const { container } = render(<CloudflareCallout />);
		expect(container).toBeEmptyDOMElement();
	});

	it('shows the title and docs link when proxied, without an inactive plugin note', () => {
		window.wcpos = {
			settings: { environment: { cloudflare: { proxied: true, plugin_active: false } } },
		};
		render(<CloudflareCallout />);
		expect(screen.getByTestId('cloudflare-callout')).toBeInTheDocument();
		expect(screen.getByText('Your store is behind Cloudflare')).toBeInTheDocument();
		expect(screen.getByRole('link', { name: 'How to add the rule' })).toHaveAttribute(
			'href',
			'https://docs.wcpos.com/support/troubleshooting/cloudflare'
		);
		expect(screen.queryByText(/The Cloudflare WordPress plugin/)).not.toBeInTheDocument();
	});

	it('shows the plugin note when the official plugin is active', () => {
		window.wcpos = {
			settings: { environment: { cloudflare: { proxied: true, plugin_active: true } } },
		};
		render(<CloudflareCallout />);
		expect(
			screen.getByText(
				'The Cloudflare WordPress plugin does not manage these rules. They live in the Cloudflare dashboard.'
			)
		).toBeInTheDocument();
	});
});
