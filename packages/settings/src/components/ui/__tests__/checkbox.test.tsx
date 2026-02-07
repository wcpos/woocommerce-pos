import * as React from 'react';

import { render, screen, fireEvent } from '@testing-library/react';

import { Checkbox } from '../checkbox';

describe('Checkbox', () => {
	it('renders a checkbox input', () => {
		render(<Checkbox />);
		expect(screen.getByRole('checkbox')).toBeInTheDocument();
	});

	it('renders a label when provided', () => {
		render(<Checkbox label="Accept terms" />);
		expect(screen.getByText('Accept terms')).toBeInTheDocument();
	});

	it('does not render a label when omitted', () => {
		const { container } = render(<Checkbox />);
		expect(container.querySelector('label')).not.toBeInTheDocument();
	});

	it('toggles checked state on click', () => {
		const onChange = vi.fn();
		render(<Checkbox onChange={onChange} />);
		fireEvent.click(screen.getByRole('checkbox'));
		expect(onChange).toHaveBeenCalledOnce();
	});

	it('respects the checked prop', () => {
		render(<Checkbox checked onChange={() => {}} />);
		expect(screen.getByRole('checkbox')).toBeChecked();
	});

	it('respects the unchecked state', () => {
		render(<Checkbox checked={false} onChange={() => {}} />);
		expect(screen.getByRole('checkbox')).not.toBeChecked();
	});

	it('disables the checkbox when disabled prop is true', () => {
		render(<Checkbox disabled />);
		expect(screen.getByRole('checkbox')).toBeDisabled();
	});

	it('associates label with input via htmlFor', () => {
		render(<Checkbox label="My checkbox" id="my-cb" />);
		const label = screen.getByText('My checkbox');
		expect(label).toHaveAttribute('for', 'my-cb');
	});
});
