import * as React from 'react';

import { render, screen, fireEvent } from '@testing-library/react';

import wpAdminReset from '../../../../../ui/src/wp-admin-reset.css?raw';
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

	it('ignores indeterminate while checked', () => {
		render(<Checkbox checked indeterminate onChange={() => {}} />);
		expect(screen.getByRole('checkbox')).not.toBePartiallyChecked();
	});

	it('disables the checkbox when disabled prop is true', () => {
		render(<Checkbox disabled />);
		expect(screen.getByRole('checkbox')).toBeDisabled();
	});

	// At <=782px wp-admin's forms.css draws a 30px tick, which the 16px box shows as a solid
	// block with the tick slipped to its bottom edge. jsdom cannot paint ::before, so pin both
	// ends of the fix: the hook class here and the reset rule that keys on it.
	it('keeps the hook class the phone-width tick rule in wp-admin-reset.css targets', () => {
		render(<Checkbox checked onChange={() => {}} />);
		expect(screen.getByRole('checkbox')).toHaveClass('wcpos-checkbox');
		// The body of the 782px block: up to its closing brace at the start of a line.
		const phoneBlock =
			wpAdminReset.match(/@media screen and \(max-width: 782px\) \{([\s\S]*?)\n\}/)?.[1] ?? '';
		expect(phoneBlock).toMatch(
			/input\.wcpos-checkbox:checked::before\s*\{[^}]*width:\s*1\.3125rem/
		);
	});

	it('associates label with input via htmlFor', () => {
		render(<Checkbox label="My checkbox" id="my-cb" />);
		const label = screen.getByText('My checkbox');
		expect(label).toHaveAttribute('for', 'my-cb');
	});
});
