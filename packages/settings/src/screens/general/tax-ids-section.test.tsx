import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { EnabledTypes } from './tax-ids-section';

const TYPES = ['eu_vat', 'es_nif', 'other'];

describe('EnabledTypes', () => {
	it('shows every type ticked when no allow-list is stored', () => {
		render(<EnabledTypes types={TYPES} enabled={[]} onChange={vi.fn()} />);

		const boxes = screen.getAllByRole('checkbox');
		expect(boxes).toHaveLength(TYPES.length);
		boxes.forEach((box) => expect(box).toBeChecked());
	});

	it('ticks only the allowed types when a restriction is stored', () => {
		render(<EnabledTypes types={TYPES} enabled={['es_nif']} onChange={vi.fn()} />);

		expect(screen.getByRole('checkbox', { name: 'ES NIF' })).toBeChecked();
		expect(screen.getByRole('checkbox', { name: 'EU VAT' })).not.toBeChecked();
		expect(screen.getByRole('checkbox', { name: 'Other' })).not.toBeChecked();
	});

	it('narrows the allow-list when a type is unticked', () => {
		const onChange = vi.fn();
		render(<EnabledTypes types={TYPES} enabled={[]} onChange={onChange} />);

		fireEvent.click(screen.getByRole('checkbox', { name: 'Other' }));

		expect(onChange).toHaveBeenCalledWith(['eu_vat', 'es_nif']);
	});

	it('stores the allow-list in canonical order, not click order', () => {
		const onChange = vi.fn();
		render(<EnabledTypes types={TYPES} enabled={['other']} onChange={onChange} />);

		fireEvent.click(screen.getByRole('checkbox', { name: 'EU VAT' }));

		expect(onChange).toHaveBeenCalledWith(['eu_vat', 'other']);
	});

	it('collapses back to the empty "no restriction" value once everything is ticked again', () => {
		const onChange = vi.fn();
		render(<EnabledTypes types={TYPES} enabled={['eu_vat', 'es_nif']} onChange={onChange} />);

		fireEvent.click(screen.getByRole('checkbox', { name: 'Other' }));

		expect(onChange).toHaveBeenCalledWith([]);
	});

	it('will not let the merchant untick the last remaining type', () => {
		render(<EnabledTypes types={TYPES} enabled={['es_nif']} onChange={vi.fn()} />);

		expect(screen.getByRole('checkbox', { name: 'ES NIF' })).toBeDisabled();
	});

	it('offers a one-click reset back to all types while a restriction is active', () => {
		const onChange = vi.fn();
		render(<EnabledTypes types={TYPES} enabled={['es_nif']} onChange={onChange} />);

		fireEvent.click(screen.getByRole('button', { name: 'Show all types' }));

		expect(onChange).toHaveBeenCalledWith([]);
	});

	it('hides the reset control when nothing is restricted', () => {
		render(<EnabledTypes types={TYPES} enabled={[]} onChange={vi.fn()} />);

		expect(screen.queryByRole('button', { name: 'Show all types' })).not.toBeInTheDocument();
	});
});
