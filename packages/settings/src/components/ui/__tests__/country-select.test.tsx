import * as React from 'react';

import { render, screen, fireEvent, within } from '@testing-library/react';

import { CountrySelect } from '../country-select';

const COUNTRIES = {
	US: 'United States',
	GB: 'United Kingdom',
	AU: 'Australia',
	DE: 'Germany',
};

describe('CountrySelect', () => {
	it('renders the placeholder when nothing is selected', () => {
		render(
			<CountrySelect
				countries={COUNTRIES}
				value=""
				onChange={() => {}}
				placeholder="Select a country"
			/>
		);
		expect(screen.getByRole('combobox')).toHaveTextContent('Select a country');
	});

	it('renders the selected country label', () => {
		render(
			<CountrySelect
				countries={COUNTRIES}
				value="DE"
				onChange={() => {}}
			/>
		);
		expect(screen.getByRole('combobox')).toHaveTextContent('Germany');
	});

	it('opens the listbox on click and lists countries sorted by label', () => {
		render(
			<CountrySelect countries={COUNTRIES} value="" onChange={() => {}} />
		);
		fireEvent.click(screen.getByRole('combobox'));
		const listbox = screen.getByRole('listbox');
		const labels = within(listbox)
			.getAllByRole('option')
			.map((node) => node.textContent?.replace(/[A-Z]{2}$/, '').trim());
		expect(labels).toEqual(['Australia', 'Germany', 'United Kingdom', 'United States']);
	});

	it('filters by typing in the search input', () => {
		render(
			<CountrySelect
				countries={COUNTRIES}
				value=""
				onChange={() => {}}
				searchPlaceholder="Search"
			/>
		);
		fireEvent.click(screen.getByRole('combobox'));
		fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'uni' } });
		const listbox = screen.getByRole('listbox');
		expect(within(listbox).getAllByRole('option')).toHaveLength(2);
		expect(listbox).toHaveTextContent('United Kingdom');
		expect(listbox).toHaveTextContent('United States');
	});

	it('also matches by country code', () => {
		render(
			<CountrySelect countries={COUNTRIES} value="" onChange={() => {}} />
		);
		fireEvent.click(screen.getByRole('combobox'));
		fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'au' } });
		const listbox = screen.getByRole('listbox');
		// "au" matches both "Australia" (label) and "AU" (code).
		expect(within(listbox).getAllByRole('option').length).toBeGreaterThanOrEqual(1);
		expect(listbox).toHaveTextContent('Australia');
	});

	it('shows the no-results label when nothing matches', () => {
		render(
			<CountrySelect
				countries={COUNTRIES}
				value=""
				onChange={() => {}}
				noResultsLabel="Nothing found"
			/>
		);
		fireEvent.click(screen.getByRole('combobox'));
		fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'zzzzz' } });
		expect(screen.getByText('Nothing found')).toBeInTheDocument();
	});

	it('commits the selection on click', () => {
		const onChange = vi.fn();
		render(
			<CountrySelect countries={COUNTRIES} value="" onChange={onChange} />
		);
		fireEvent.click(screen.getByRole('combobox'));
		fireEvent.click(screen.getByText('Germany'));
		expect(onChange).toHaveBeenCalledWith('DE');
	});

	it('navigates with arrow keys and selects with Enter', () => {
		const onChange = vi.fn();
		render(
			<CountrySelect countries={COUNTRIES} value="" onChange={onChange} />
		);
		fireEvent.click(screen.getByRole('combobox'));
		const search = screen.getByRole('searchbox');
		// Sorted: Australia (0), Germany (1), United Kingdom (2), United States (3).
		fireEvent.keyDown(search, { key: 'ArrowDown' });
		fireEvent.keyDown(search, { key: 'ArrowDown' });
		fireEvent.keyDown(search, { key: 'Enter' });
		expect(onChange).toHaveBeenCalledWith('GB');
	});

	it('closes on Escape without committing', () => {
		const onChange = vi.fn();
		render(
			<CountrySelect countries={COUNTRIES} value="" onChange={onChange} />
		);
		fireEvent.click(screen.getByRole('combobox'));
		fireEvent.keyDown(screen.getByRole('searchbox'), { key: 'Escape' });
		expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
		expect(onChange).not.toHaveBeenCalled();
	});

	it('renders a clear option when clearable and commits ""', () => {
		const onChange = vi.fn();
		render(
			<CountrySelect
				countries={COUNTRIES}
				value="DE"
				onChange={onChange}
				clearable
				clearLabel="None"
			/>
		);
		fireEvent.click(screen.getByRole('combobox'));
		fireEvent.click(screen.getByText('None'));
		expect(onChange).toHaveBeenCalledWith('');
	});

	it('clear option is reachable via keyboard navigation', () => {
		const onChange = vi.fn();
		render(
			<CountrySelect
				countries={COUNTRIES}
				value="DE"
				onChange={onChange}
				clearable
				clearLabel="None"
			/>
		);
		fireEvent.click(screen.getByRole('combobox'));
		const search = screen.getByRole('searchbox');
		// Order: None (0, clear), Australia (1), Germany (2)…
		// Initial active is the selected country (Germany, index 2). Two ArrowUps land on the clear option.
		fireEvent.keyDown(search, { key: 'ArrowUp' });
		fireEvent.keyDown(search, { key: 'ArrowUp' });
		fireEvent.keyDown(search, { key: 'Enter' });
		expect(onChange).toHaveBeenCalledWith('');
	});

	it('does not open when disabled', () => {
		render(
			<CountrySelect
				countries={COUNTRIES}
				value=""
				onChange={() => {}}
				disabled
			/>
		);
		fireEvent.click(screen.getByRole('combobox'));
		expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
	});
});
