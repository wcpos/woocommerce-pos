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
			ES: 'Spain',
			FR: 'France',
			GB: 'United Kingdom',
			IT: 'Italy',
			NL: 'Netherlands',
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

	it('uses es_nif for ES stores', () => {
		setStoreCountry('ES');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('B12345674');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'es_nif', value: 'B12345674', country: 'ES' },
		]);
	});

	it('uses it_piva for IT stores', () => {
		setStoreCountry('IT');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('12345678901');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'it_piva', value: '12345678901', country: 'IT' },
		]);
	});

	it('uses nl_kvk for NL stores', () => {
		setStoreCountry('NL');
		const onChange = vi.fn();

		render(<TaxIdsField value={[]} onChange={onChange} labels={baseLabels} />);

		fireEvent.click(screen.getByRole('button', { name: baseLabels.add }));
		commitDraftValue('12345678');

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'nl_kvk', value: '12345678', country: 'NL' },
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

describe('TaxIdsField tax ID country/type syncing', () => {
	afterEach(() => {
		delete (window as any).wcpos;
	});

	it('updates the country when the tax ID type changes to a country-specific default', () => {
		setStoreCountry('DE');
		const onChange = vi.fn();

		render(
			<TaxIdsField
				value={[{ type: 'de_ust_id', value: 'DE123456789', country: 'DE' }]}
				onChange={onChange}
				labels={baseLabels}
			/>
		);

		fireEvent.change(screen.getByRole('combobox', { name: baseLabels.type }), {
			target: { value: 'us_ein' },
		});

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'us_ein', value: 'DE123456789', country: 'US' },
		]);
	});

	it('updates the tax ID type when the country changes to a mapped default', () => {
		setStoreCountry('DE');
		const onChange = vi.fn();

		render(
			<TaxIdsField
				value={[{ type: 'us_ein', value: '12-3456789', country: 'US' }]}
				onChange={onChange}
				labels={baseLabels}
			/>
		);

		fireEvent.click(screen.getByRole('combobox', { name: baseLabels.country }));
		fireEvent.click(screen.getByRole('option', { name: /Germany/ }));

		expect(onChange).toHaveBeenCalledWith([
			{ type: 'de_ust_id', value: '12-3456789', country: 'DE' },
		]);
	});

	it('uses the selected tax ID type example as the value placeholder', () => {
		setStoreCountry('US');
		const onChange = vi.fn();

		render(
			<TaxIdsField
				value={[{ type: 'us_ein', value: '', country: 'US' }]}
				onChange={onChange}
				labels={baseLabels}
			/>
		);

		expect(screen.getByRole('textbox', { name: baseLabels.value })).toHaveAttribute(
			'placeholder',
			'12-3456789'
		);
	});
});
