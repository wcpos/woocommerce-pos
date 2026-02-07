import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { FormSection } from '../form-section';

describe('FormSection', () => {
	it('renders children', () => {
		render(
			<FormSection>
				<p>Section content</p>
			</FormSection>
		);
		expect(screen.getByText('Section content')).toBeInTheDocument();
	});

	it('renders a title when provided', () => {
		render(
			<FormSection title="General Settings">
				<p>Content</p>
			</FormSection>
		);
		expect(screen.getByText('General Settings')).toBeInTheDocument();
	});

	it('does not render a title element when title is omitted', () => {
		const { container } = render(
			<FormSection>
				<p>Content</p>
			</FormSection>
		);
		expect(container.querySelector('h3')).not.toBeInTheDocument();
	});

	it('renders a description when provided along with title', () => {
		render(
			<FormSection title="Products" description="Configure product settings">
				<p>Content</p>
			</FormSection>
		);
		expect(screen.getByText('Configure product settings')).toBeInTheDocument();
	});

	it('does not render a description when omitted', () => {
		render(
			<FormSection title="Products">
				<p>Content</p>
			</FormSection>
		);
		// Only the title's container div, no description <p> inside the heading area
		const headings = screen.getByText('Products');
		const headingContainer = headings.parentElement;
		expect(headingContainer?.querySelector('p')).not.toBeInTheDocument();
	});
});
