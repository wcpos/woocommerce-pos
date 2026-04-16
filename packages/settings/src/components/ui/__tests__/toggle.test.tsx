import * as React from 'react';

import { render, screen, fireEvent } from '@testing-library/react';

import { Toggle } from '../toggle';

describe('Toggle', () => {
	it('renders as a switch', () => {
		render(<Toggle checked={false} onChange={() => {}} aria-label="Test toggle" />);
		expect(screen.getByRole('switch')).toBeInTheDocument();
	});

	it('calls onChange when clicked', () => {
		const onChange = vi.fn();
		render(<Toggle checked={false} onChange={onChange} aria-label="Test toggle" />);
		fireEvent.click(screen.getByRole('switch'));
		expect(onChange).toHaveBeenCalledWith(true);
	});

	it('reflects checked state', () => {
		render(<Toggle checked onChange={() => {}} aria-label="Test toggle" />);
		const switchEl = screen.getByRole('switch');
		expect(switchEl).toHaveAttribute('aria-checked', 'true');
	});

	it('reflects unchecked state', () => {
		render(<Toggle checked={false} onChange={() => {}} aria-label="Test toggle" />);
		const switchEl = screen.getByRole('switch');
		expect(switchEl).toHaveAttribute('aria-checked', 'false');
	});

	it('renders label text when provided', () => {
		render(<Toggle checked={false} onChange={() => {}} label="Enable feature" />);
		expect(screen.getByText('Enable feature')).toBeInTheDocument();
	});

	it('renders description when provided along with label', () => {
		render(
			<Toggle
				checked={false}
				onChange={() => {}}
				label="Enable feature"
				description="Turns on the thing"
			/>
		);
		expect(screen.getByText('Turns on the thing')).toBeInTheDocument();
	});

	it('disables the switch when disabled prop is true', () => {
		const onChange = vi.fn();
		render(<Toggle checked={false} onChange={onChange} disabled aria-label="Test toggle" />);
		const switchEl = screen.getByRole('switch');
		expect(switchEl).toBeDisabled();
	});
});
