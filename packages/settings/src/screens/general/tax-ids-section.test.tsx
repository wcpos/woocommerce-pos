import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { TaxIdsSection } from './tax-ids-section';

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn(() => new Promise(() => {})) }));
vi.mock('../../hooks/use-settings-api', () => ({
	default: () => ({ data: { write_map: {} }, mutate: vi.fn() }),
}));

function renderBanner(plugins: string[]) {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	client.setQueryData(['tax_ids_detection'], {
		plugins,
		default_write_map: {},
		composed_write_map: {},
		types: [],
	});
	render(
		<QueryClientProvider client={client}>
			<TaxIdsSection />
		</QueryClientProvider>
	);
}

// The banner line: the names in a medium-weight span, the words around it regular.
function bannerLine(names: string) {
	const span = screen.getByText(names);
	expect(span.tagName).toBe('SPAN');
	expect(span).toHaveClass('wcpos:font-medium');
	expect(span.parentElement).toHaveClass('wcpos:font-normal');
	return span.parentElement?.textContent;
}

describe('TaxIdsSection detection banner', () => {
	it('one detected plugin reads is installed', () => {
		renderBanner(['germanized']);
		expect(bannerLine('WooCommerce Germanized')).toBe('WooCommerce Germanized is installed.');
	});

	it('two detected plugins read are installed', () => {
		renderBanner(['germanized', 'aelia_eu_vat']);
		expect(bannerLine('WooCommerce Germanized, Aelia EU VAT Assistant')).toBe(
			'WooCommerce Germanized, Aelia EU VAT Assistant are installed.'
		);
	});

	it('a plugin name with an ampersand renders as written', () => {
		renderBanner(['Tax & VAT for WooCommerce']);
		expect(bannerLine('Tax & VAT for WooCommerce')).toBe('Tax & VAT for WooCommerce is installed.');
	});
});
