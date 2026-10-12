import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import GatewayModal from './gateway-modal';

import type { GatewayProps } from './gateways';

const gateway: GatewayProps = {
	id: 'pos_cash',
	title: 'Cash',
	description: 'Pay with cash',
	enabled: true,
	kind: 'cash',
	rounding_increment: '',
	capture_mode: 'manual',
	default_reader: '',
	allowed_readers: [],
	lock_to_default: false,
};

describe('GatewayModal rounding', () => {
	it('offers the supported increments with no rounding selected by default', () => {
		render(<GatewayModal gateway={gateway} mutate={vi.fn()} closeModal={vi.fn()} />);
		expect(screen.getByRole('combobox', { name: 'Rounding' })).toHaveValue('');
		expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual([
			'No rounding',
			'0.05',
			'0.10',
			'0.25',
			'0.50',
			'1',
			'5',
			'10',
		]);
	});

	it.each(['0.05', ''])('saves increment %j with title and description', (increment) => {
		const mutate = vi.fn();
		const closeModal = vi.fn();
		render(
			<GatewayModal
				gateway={{ ...gateway, rounding_increment: '0.10' }}
				mutate={mutate}
				closeModal={closeModal}
			/>
		);
		const select = screen.getByRole('combobox', { name: 'Rounding' });
		expect(select).toHaveValue('0.10');
		fireEvent.change(select, { target: { value: increment } });
		fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Till' } });
		fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Cash only' } });
		fireEvent.click(screen.getByRole('button', { name: 'Save' }));
		expect(mutate).toHaveBeenCalledWith({
			gateways: {
				pos_cash: { title: 'Till', description: 'Cash only', rounding_increment: increment },
			},
		});
		expect(closeModal).toHaveBeenCalledOnce();
	});

	it('uses kind rather than the gateway id to show rounding', () => {
		render(
			<GatewayModal gateway={{ ...gateway, id: 'cod' }} mutate={vi.fn()} closeModal={vi.fn()} />
		);
		expect(screen.getByRole('combobox', { name: 'Rounding' })).toBeInTheDocument();
	});

	it.each(['card', 'bank_transfer', 'other'])('hides rounding for %s gateways', (kind) => {
		render(<GatewayModal gateway={{ ...gateway, kind }} mutate={vi.fn()} closeModal={vi.fn()} />);
		expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
	});
});
