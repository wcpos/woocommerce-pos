import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { FormRow } from '../form-row';

describe('FormRow', () => {
	it('renders children', () => {
		render(
			<FormRow>
				<input type="text" data-testid="my-input" />
			</FormRow>
		);
		expect(screen.getByTestId('my-input')).toBeInTheDocument();
	});

	it('renders a label when provided', () => {
		render(
			<FormRow label="Email">
				<input type="email" />
			</FormRow>
		);
		expect(screen.getByText('Email')).toBeInTheDocument();
	});

	it('does not render a label when omitted', () => {
		const { container } = render(
			<FormRow>
				<input type="text" />
			</FormRow>
		);
		expect(container.querySelector('label')).not.toBeInTheDocument();
	});

	it('renders a description when provided', () => {
		render(
			<FormRow description="Enter your email address">
				<input type="email" />
			</FormRow>
		);
		expect(screen.getByText('Enter your email address')).toBeInTheDocument();
	});

	it('does not render a description when omitted', () => {
		const { container } = render(
			<FormRow>
				<input type="text" />
			</FormRow>
		);
		// No <p> element for description
		expect(container.querySelector('p')).not.toBeInTheDocument();
	});

	it('passes htmlFor to the label element', () => {
		render(
			<FormRow label="Name" htmlFor="name-input">
				<input id="name-input" type="text" />
			</FormRow>
		);
		const label = screen.getByText('Name');
		expect(label).toHaveAttribute('for', 'name-input');
	});
});
