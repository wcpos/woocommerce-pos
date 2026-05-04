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

function setStoreCountry(country: string | undefined) {
	(window as any).wcpos = (window as any).wcpos || {};
	(window as any).wcpos.settings = {
		countries: {
			AT: 'Austria',
			DE: 'Germany',
			FR: 'France',
			GB: 'United Kingdom',
			US: 'United States',
		},
	};
	if (country) {
		(window as any).wcpos.settings.storeCountry = country;
	}
}

function commitDraftValue(value: string) {
	const valueInput = screen.getByRole('textbox', { name: baseLabels.value });
	fireEvent.change(valueInput, { target: { value } });
	fireEvent.blur(valueInput);
}

describe('TaxIdsField addRow defaults', () => {
	afterEach(() => {
		delete (window as any).wcpos;
	});

	it('uses the country-specific type for DE stores (de_ust_id)', () => {
		setStoreCountry('DE');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('DE123456789');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'de_ust_id', value: 'DE123456789', country: 'DE' },
		]);
	});

	it('uses fr_siret for FR stores', () => {
		setStoreCountry('FR');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('12345678901234');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'fr_siret', value: '12345678901234', country: 'FR' },
		]);
	});

	it('falls through to eu_vat for EU countries without a country-specific entry (AT)', () => {
		setStoreCountry('AT');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('ATU12345678');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'eu_vat', value: 'ATU12345678', country: 'AT' },
		]);
	});

	it('uses the country-specific tax ID type for non-EU mapped countries (US → us_ein)', () => {
		setStoreCountry('US');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('12-3456789');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'us_ein', value: '12-3456789', country: 'US' },
		]);
	});

	it('uses gb_vat for GB stores', () => {
		setStoreCountry('GB');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('GB123456789');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'gb_vat', value: 'GB123456789', country: 'GB' },
		]);
	});

	it('falls back to type="other" with no country when storeCountry is absent', () => {
		setStoreCountry(undefined);
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('XX1');

		expect(onChange).toHaveBeenCalledWith([{ type: 'other', value: 'XX1' }]);
	});
});
