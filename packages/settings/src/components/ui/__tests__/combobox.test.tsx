import * as React from 'react';

import { fireEvent, render, screen, within } from '@testing-library/react';

import { Combobox } from '../combobox';

const FRUIT = [
	{ value: 'apple', label: 'Apple' },
	{ value: 'banana', label: 'Banana' },
	{ value: 'cherry', label: 'Cherry' },
];

function renderCombobox(props: Partial<React.ComponentProps<typeof Combobox>> = {}) {
	const onChange = vi.fn();
	const utils = render(
		<Combobox value="" options={FRUIT} onChange={onChange} {...props} />
	);
	return { onChange, ...utils };
}

describe('Combobox', () => {
	it('renders the placeholder when nothing is selected', () => {
		renderCombobox({ placeholder: 'Pick a fruit' });
		expect(screen.getByRole('combobox')).toHaveAttribute('placeholder', 'Pick a fruit');
	});

	it('shows the selected option label in the input', () => {
		renderCombobox({ value: 'banana' });
		expect(screen.getByRole('combobox')).toHaveValue('Banana');
	});

	it('opens the listbox on focus and lists every option', () => {
		renderCombobox();
		fireEvent.focus(screen.getByRole('combobox'));
		const listbox = screen.getByRole('listbox');
		expect(within(listbox).getAllByRole('option')).toHaveLength(3);
	});

	it('filters options as the user types', () => {
		renderCombobox();
		const input = screen.getByRole('combobox');
		fireEvent.focus(input);
		fireEvent.change(input, { target: { value: 'an' } });
		const listbox = screen.getByRole('listbox');
		expect(within(listbox).getAllByRole('option')).toHaveLength(1);
		expect(listbox).toHaveTextContent('Banana');
	});

	it('commits a clicked option', () => {
		const { onChange } = renderCombobox();
		fireEvent.focus(screen.getByRole('combobox'));
		fireEvent.click(screen.getByText('Cherry'));
		expect(onChange).toHaveBeenCalledWith('cherry');
	});

	it('navigates with arrow keys and selects with Enter', () => {
		const { onChange } = renderCombobox();
		const input = screen.getByRole('combobox');
		fireEvent.focus(input);
		// Opening doesn't auto-highlight; first ArrowDown lands on Apple (0),
		// second ArrowDown on Banana (1).
		fireEvent.keyDown(input, { key: 'ArrowDown' });
		fireEvent.keyDown(input, { key: 'ArrowDown' });
		fireEvent.keyDown(input, { key: 'Enter' });
		expect(onChange).toHaveBeenCalledWith('banana');
	});

	it('shows the no-results label when nothing matches', () => {
		renderCombobox({ noResultsLabel: 'Nothing found' });
		const input = screen.getByRole('combobox');
		fireEvent.focus(input);
		fireEvent.change(input, { target: { value: 'zzz' } });
		expect(screen.getByText('Nothing found')).toBeInTheDocument();
	});

	it('reverts to the committed value on Escape', () => {
		renderCombobox({ value: 'apple' });
		const input = screen.getByRole('combobox');
		fireEvent.focus(input);
		fireEvent.change(input, { target: { value: 'zzz' } });
		fireEvent.keyDown(input, { key: 'Escape' });
		expect(input).toHaveValue('Apple');
	});

	it('does not open or commit when disabled', () => {
		const { onChange } = renderCombobox({ disabled: true });
		const input = screen.getByRole('combobox');
		fireEvent.focus(input);
		expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
		fireEvent.keyDown(input, { key: 'Enter' });
		expect(onChange).not.toHaveBeenCalled();
	});

	describe('strict mode', () => {
		it('discards typed text that does not match an option', () => {
			const { onChange } = renderCombobox({ value: 'apple' });
			const input = screen.getByRole('combobox');
			fireEvent.focus(input);
			fireEvent.change(input, { target: { value: 'made up' } });
			fireEvent.keyDown(input, { key: 'Enter' });
			expect(onChange).not.toHaveBeenCalled();
			expect(input).toHaveValue('Apple');
		});
	});

	describe('editable mode', () => {
		it('commits typed text on Enter', () => {
			const { onChange } = renderCombobox({ allowCustomValue: true });
			const input = screen.getByRole('combobox');
			fireEvent.focus(input);
			fireEvent.change(input, { target: { value: 'durian' } });
			fireEvent.keyDown(input, { key: 'Enter' });
			expect(onChange).toHaveBeenCalledWith('durian');
		});

		it('shows a Create entry when createLabel is provided', () => {
			renderCombobox({
				allowCustomValue: true,
				createLabel: (q) => `Create "${q}"`,
			});
			const input = screen.getByRole('combobox');
			fireEvent.focus(input);
			fireEvent.change(input, { target: { value: 'durian' } });
			expect(screen.getByText('Create "durian"')).toBeInTheDocument();
		});

		it('clicking the Create entry commits the typed value', () => {
			const { onChange } = renderCombobox({
				allowCustomValue: true,
				createLabel: (q) => `Create "${q}"`,
			});
			const input = screen.getByRole('combobox');
			fireEvent.focus(input);
			fireEvent.change(input, { target: { value: 'durian' } });
			fireEvent.click(screen.getByText('Create "durian"'));
			expect(onChange).toHaveBeenCalledWith('durian');
		});

		it('selecting an existing option still commits the option value', () => {
			const { onChange } = renderCombobox({ allowCustomValue: true });
			const input = screen.getByRole('combobox');
			fireEvent.focus(input);
			fireEvent.click(screen.getByText('Banana'));
			expect(onChange).toHaveBeenCalledWith('banana');
		});

		it('commits an empty string when the user clears the input', () => {
			const { onChange } = renderCombobox({
				value: 'apple',
				allowCustomValue: true,
			});
			const input = screen.getByRole('combobox');
			fireEvent.focus(input);
			fireEvent.change(input, { target: { value: '' } });
			fireEvent.keyDown(input, { key: 'Enter' });
			expect(onChange).toHaveBeenCalledWith('');
		});

		it('shows the raw value when no option matches and custom values are allowed', () => {
			renderCombobox({ value: 'kiwi', allowCustomValue: true });
			expect(screen.getByRole('combobox')).toHaveValue('kiwi');
		});
	});

	describe('remote search', () => {
		it('forwards every keystroke via onQuery and skips client-side filtering', () => {
			const onQuery = vi.fn();
			renderCombobox({ onQuery });
			const input = screen.getByRole('combobox');
			fireEvent.focus(input);
			fireEvent.change(input, { target: { value: 'an' } });
			expect(onQuery).toHaveBeenLastCalledWith('an');
			// Without remote filtering, the parent supplies the list — Combobox must not filter.
			const listbox = screen.getByRole('listbox');
			expect(within(listbox).getAllByRole('option')).toHaveLength(3);
		});

		it('renders a loading label while loading', () => {
			renderCombobox({ loading: true, loadingLabel: 'Loading…' });
			fireEvent.focus(screen.getByRole('combobox'));
			expect(screen.getByText('Loading…')).toBeInTheDocument();
		});
	});
});
