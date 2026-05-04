import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';

import TaxIdsField from '../tax-ids-field';

const baseLabels = {
	add: 'Add tax ID',
	type: 'Type',
	value: 'Value',
	country: 'Country',
	label: 'Custom label',
	remove: 'Remove',
	empty: 'No additional store tax IDs configured.',
};

describe('TaxIdsField addRow behavior', () => {
	beforeEach(() => {
		(window as any).wcpos = (window as any).wcpos || {};
		(window as any).wcpos.settings = {
			countries: { DE: 'Germany', US: 'United States' },
		};
	});

	afterEach(() => {
		delete (window as any).wcpos;
	});

	it('pre-fills new rows with wcpos.settings.storeCountry when present', () => {
		(window as any).wcpos.settings.storeCountry = 'DE';
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));

		const valueInput = screen.getByRole('textbox', { name: baseLabels.value });
		fireEvent.change(valueInput, { target: { value: 'DE123456789' } });
		fireEvent.blur(valueInput);

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'other', value: 'DE123456789', country: 'DE' },
		]);
	});

	it('omits country on new rows when storeCountry is absent', () => {
		// storeCountry intentionally not set
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));

		const valueInput = screen.getByRole('textbox', { name: baseLabels.value });
		fireEvent.change(valueInput, { target: { value: 'XX1' } });
		fireEvent.blur(valueInput);

		expect(onChange).toHaveBeenCalledWith([{ type: 'other', value: 'XX1' }]);
	});
});
