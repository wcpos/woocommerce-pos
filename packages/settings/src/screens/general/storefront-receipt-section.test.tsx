import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { StorefrontReceiptSection } from './storefront-receipt-section';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

function renderSection(props: React.ComponentProps<typeof StorefrontReceiptSection>) {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return render(
		<QueryClientProvider client={client}>
			<StorefrontReceiptSection {...props} />
		</QueryClientProvider>
	);
}

describe('StorefrontReceiptSection', () => {
	beforeEach(() => {
		apiFetchMock.mockReset();
		apiFetchMock.mockResolvedValue([
			{
				id: 12,
				title: 'Standard Receipt',
				status: 'publish',
				is_active: true,
				engine: 'logicless',
			},
		]);
	});

	it('is opt-in: renders the toggle off and hides the template picker when disabled', () => {
		// Arrange / Act
		renderSection({ enabled: false, template: '', onChange: vi.fn() });

		// Assert
		const toggle = screen.getByRole('switch');
		expect(toggle).toHaveAttribute('aria-checked', 'false');
		expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
	});

	it('enables the feature when the toggle is switched on', () => {
		// Arrange
		const onChange = vi.fn();
		renderSection({ enabled: false, template: '', onChange });

		// Act
		fireEvent.click(screen.getByRole('switch'));

		// Assert
		expect(onChange).toHaveBeenCalledWith({ storefront_receipt_enabled: true });
	});

	it('shows the template picker with the active-template default and available templates when enabled', async () => {
		// Arrange / Act
		renderSection({ enabled: true, template: '', onChange: vi.fn() });

		// Assert
		const select = await screen.findByRole('combobox');
		expect(select).toBeInTheDocument();
		expect(screen.getByRole('option', { name: 'Use active receipt template' })).toBeInTheDocument();
		await waitFor(() =>
			expect(screen.getByRole('option', { name: 'Standard Receipt' })).toBeInTheDocument()
		);
	});

	it('shows a saved template as unavailable when it is no longer returned by the API', async () => {
		// Arrange / Act
		renderSection({ enabled: true, template: 'deleted-template', onChange: vi.fn() });

		// Assert
		const select = await screen.findByRole('combobox');
		const option = await screen.findByRole('option', {
			name: 'Unavailable template (deleted-template)',
		});
		expect(select).toHaveValue('deleted-template');
		expect(option).toBeDisabled();
	});

	it('includes virtual templates that do not have a status field', async () => {
		// Arrange
		apiFetchMock.mockResolvedValue([
			{
				id: 'plugin-core',
				title: 'Legacy PHP Template',
				is_active: false,
				is_virtual: true,
				engine: 'legacy-php',
			},
		]);

		// Act
		renderSection({ enabled: true, template: '', onChange: vi.fn() });

		// Assert
		expect(await screen.findByRole('option', { name: 'Legacy PHP Template' })).toBeInTheDocument();
	});

	it('reports the pinned template when a specific one is selected', async () => {
		// Arrange
		const onChange = vi.fn();
		renderSection({ enabled: true, template: '', onChange });
		const select = await screen.findByRole('combobox');
		await waitFor(() =>
			expect(screen.getByRole('option', { name: 'Standard Receipt' })).toBeInTheDocument()
		);

		// Act
		fireEvent.change(select, { target: { value: '12' } });

		// Assert
		expect(onChange).toHaveBeenCalledWith({ storefront_receipt_template: '12' });
	});
});
