import * as React from 'react';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import GatewayModal from '../gateway-modal';
import strings from '../../../translations/locales/en/wp-admin-settings.json';

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn() }));
vi.mock('../../../translations', () => ({
	t: (key: keyof typeof strings) => strings[key] || key,
	Trans: () => null,
}));

const gateway = {
	id: 'terminal',
	title: 'Card',
	description: 'Pay by card',
	enabled: true,
	capture_mode: 'server',
	default_reader: '',
	allowed_readers: [],
	lock_to_default: false,
};
const readers = [{ id: 'a', label: 'Front', status: 'online' }, { id: 'b' }];

beforeEach(() => {
	vi.mocked(apiFetch).mockReset().mockResolvedValue({ readers });
});

it('renders server terminals and saves availability, default and lock', async () => {
	const mutate = vi.fn();
	render(<GatewayModal gateway={gateway} mutate={mutate} closeModal={vi.fn()} />);
	expect(screen.getByText('Terminals')).toBeInTheDocument();
	await screen.findByText('Front');
	expect(screen.getByText('online')).toBeInTheDocument();
	expect(screen.getByText('b')).toBeInTheDocument();
	const available = screen.getAllByRole('checkbox', { name: 'Available at the till' });
	expect(available[0]).toBeChecked();
	expect(available[1]).toBeChecked();
	const lock = screen.getByRole('checkbox', { name: 'Lock to the default terminal' });
	expect(lock).toBeDisabled();
	fireEvent.click(available[0]);
	expect(available[0]).not.toBeChecked();
	// Only 'b' is left available, so it cannot be unchecked: a stored empty list
	// means "every terminal", not "none".
	expect(available[1]).toBeDisabled();
	fireEvent.click(screen.getAllByRole('radio', { name: 'Default' })[0]);
	expect(available[0]).toBeChecked();
	expect(available[1]).not.toBeDisabled();
	fireEvent.click(available[1]);
	fireEvent.click(lock);
	fireEvent.click(screen.getByRole('button', { name: 'Save' }));
	expect(mutate).toHaveBeenCalledWith({
		gateways: {
			terminal: {
				title: 'Card',
				description: 'Pay by card',
				default_reader: 'a',
				allowed_readers: ['a'],
				lock_to_default: true,
			},
		},
	});
});

it('saves every reader checked as the all-readers sentinel', async () => {
	const mutate = vi.fn();
	render(
		<GatewayModal
			gateway={{ ...gateway, allowed_readers: ['a'], default_reader: 'a' }}
			mutate={mutate}
			closeModal={vi.fn()}
		/>
	);
	await screen.findByText('Front');
	const available = screen.getAllByRole('checkbox', { name: 'Available at the till' });
	fireEvent.click(available[0]);
	expect(screen.getAllByRole('radio')[0]).not.toBeChecked();
	fireEvent.click(available[0]);
	fireEvent.click(available[1]);
	fireEvent.click(screen.getByRole('button', { name: 'Save' }));
	expect(mutate.mock.calls[0][0].gateways.terminal).toMatchObject({
		allowed_readers: [],
		default_reader: '',
		lock_to_default: false,
	});
});

it('does not render or save terminal settings for non-server gateways', () => {
	const mutate = vi.fn();
	render(
		<GatewayModal
			gateway={{ ...gateway, capture_mode: 'manual' }}
			mutate={mutate}
			closeModal={vi.fn()}
		/>
	);
	expect(screen.queryByText('Terminals')).not.toBeInTheDocument();
	expect(apiFetch).not.toHaveBeenCalled();
	fireEvent.click(screen.getByRole('button', { name: 'Save' }));
	expect(mutate).toHaveBeenCalledWith({
		gateways: {
			terminal: {
				title: 'Card',
				description: 'Pay by card',
			},
		},
	});
});

it('shows loading, empty and error states and refreshes discovery', async () => {
	vi.mocked(apiFetch)
		.mockResolvedValueOnce({ readers: [] })
		.mockRejectedValueOnce({ message: 'Offline' });
	render(<GatewayModal gateway={gateway} mutate={vi.fn()} closeModal={vi.fn()} />);
	expect(screen.getByText('Loading…')).toBeInTheDocument();
	await screen.findByText('No terminals found.');
	expect(apiFetch).toHaveBeenCalledWith({
		path: 'wcpos/v2/settings/payment-gateways/readers?gateway_id=terminal&wcpos=1',
	});
	fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
	await waitFor(() => expect(screen.getByText('Offline')).toBeInTheDocument());
	expect(apiFetch).toHaveBeenLastCalledWith({
		path: 'wcpos/v2/settings/payment-gateways/readers?gateway_id=terminal&wcpos=1&refresh=1',
	});
});

it('renders no terminals block for a gateway that is not server-mode', () => {
	render(
		<GatewayModal
			gateway={{ ...gateway, capture_mode: 'manual' }}
			mutate={vi.fn()}
			closeModal={vi.fn()}
		/>
	);
	expect(screen.queryByText('Terminals')).not.toBeInTheDocument();
	expect(apiFetch).not.toHaveBeenCalled();
	fireEvent.click(screen.getByRole('button', { name: 'Save' }));
});

it('shows the provider error and recovers on refresh', async () => {
	vi.mocked(apiFetch)
		.mockRejectedValueOnce({ message: 'Stripe is unreachable' })
		.mockResolvedValueOnce({ readers });
	render(<GatewayModal gateway={gateway} mutate={vi.fn()} closeModal={vi.fn()} />);
	await screen.findByText('Stripe is unreachable');
	fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
	await screen.findByText('Front');
	expect(vi.mocked(apiFetch).mock.calls[1][0].path).toContain('refresh=1');
});

it('clears the default with the no-default option, keeping the reader available', async () => {
	const mutate = vi.fn();
	render(
		<GatewayModal
			gateway={{ ...gateway, default_reader: 'a', allowed_readers: ['a'], lock_to_default: true }}
			mutate={mutate}
			closeModal={vi.fn()}
		/>
	);
	await screen.findByText('Front');
	const available = screen.getAllByRole('checkbox', { name: 'Available at the till' });
	expect(available[0]).toBeDisabled(); // the only available reader
	fireEvent.click(screen.getByRole('radio', { name: 'No default — the cashier chooses' }));
	expect(screen.getByRole('checkbox', { name: 'Lock to the default terminal' })).toBeDisabled();
	fireEvent.click(screen.getByRole('button', { name: 'Save' }));
	expect(mutate.mock.calls[0][0].gateways.terminal).toMatchObject({
		default_reader: '',
		allowed_readers: ['a'],
		lock_to_default: false,
	});
});
