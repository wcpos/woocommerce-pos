import * as React from 'react';

import { render, screen, fireEvent } from '@testing-library/react';

import {
	Checkbox,
	Modal,
	Select,
	TextArea,
	TextInput,
	Toggle,
	Tooltip,
	type OptionProps,
} from '@wcpos/ui';

describe('shared form controls from @wcpos/ui', () => {
	it('renders a toggle switch and calls onChange with the next checked state', () => {
		const onChange = vi.fn();
		render(<Toggle checked={false} onChange={onChange} label="Enable feature" />);

		fireEvent.click(screen.getByRole('switch', { name: 'Enable feature' }));

		expect(onChange).toHaveBeenCalledWith(true);
	});

	it('renders a checkbox with label and disabled cursor classes', () => {
		render(<Checkbox label="Accept" disabled />);

		const checkbox = screen.getByRole('checkbox', { name: 'Accept' });
		expect(checkbox).toBeDisabled();
		expect(checkbox.className).toContain('wcpos:cursor-not-allowed');
	});

	it('renders text inputs and text areas with error classes', () => {
		render(
			<>
				<TextInput aria-label="Name" error />
				<TextArea aria-label="Notes" error />
			</>
		);

		expect(screen.getByRole('textbox', { name: 'Name' }).className).toContain(
			'wcpos:border-red-300'
		);
		expect(screen.getByRole('textbox', { name: 'Notes' }).className).toContain(
			'wcpos:border-red-300'
		);
	});

	it('renders a select and returns the selected option object', () => {
		const onChange = vi.fn();
		const options: OptionProps[] = [
			{ value: 'draft', label: 'Draft' },
			{ value: 'publish', label: 'Publish', meta: 'extra' },
		];

		render(
			<Select
				aria-label="Status"
				value="draft"
				options={options}
				onChange={onChange}
				placeholder="Choose status"
			/>
		);
		fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), {
			target: { value: 'publish' },
		});

		expect(onChange).toHaveBeenCalledWith(options[1]);
	});

	it('renders modal content only when open', () => {
		const { rerender } = render(
			<Modal open={false} onClose={() => {}} title="Shared modal">
				<p>Modal content</p>
			</Modal>
		);
		expect(screen.queryByText('Shared modal')).not.toBeInTheDocument();

		rerender(
			<Modal open onClose={() => {}} title="Shared modal" description="Helpful description">
				<p>Modal content</p>
			</Modal>
		);

		expect(screen.getByRole('dialog', { name: 'Shared modal' })).toBeInTheDocument();
		expect(screen.getByText('Helpful description')).toBeInTheDocument();
		expect(screen.getByText('Modal content')).toBeInTheDocument();
	});

	it('closes an open modal with the Escape key', () => {
		const onClose = vi.fn();
		render(
			<Modal open onClose={onClose} title="Shared modal">
				<p>Modal content</p>
			</Modal>
		);

		fireEvent.keyDown(document, { key: 'Escape' });

		expect(onClose).toHaveBeenCalledWith(false);
	});

	it('shows tooltip text on hover', () => {
		render(
			<Tooltip text="Helpful tip">
				<button type="button">Info</button>
			</Tooltip>
		);

		fireEvent.mouseEnter(screen.getByRole('button', { name: 'Info' }));

		expect(screen.getByRole('tooltip')).toHaveTextContent('Helpful tip');
	});
});
