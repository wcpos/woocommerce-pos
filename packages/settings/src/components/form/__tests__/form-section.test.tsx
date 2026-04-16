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

	it('renders headerRight content alongside the title', () => {
		render(
			<FormSection
				title="Authorized Users"
				headerRight={<button type="button">Reset</button>}
			>
				<p>Content</p>
			</FormSection>
		);
		expect(screen.getByText('Authorized Users')).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'Reset' })).toBeInTheDocument();
	});

	it('renders headerRight without a title or description', () => {
		render(
			<FormSection headerRight={<span>Count: 3</span>}>
				<p>Content</p>
			</FormSection>
		);
		expect(screen.getByText('Count: 3')).toBeInTheDocument();
	});

	it('renders headerRight when value is the number 0', () => {
		// Regression: a truthy check on headerRight previously hid numeric 0
		// (e.g. a "0 selected" count), even though React renders 0 as "0".
		render(
			<FormSection title="Selected" headerRight={0}>
				<p>Content</p>
			</FormSection>
		);
		expect(screen.getByText('Selected')).toBeInTheDocument();
		expect(screen.getByText('0')).toBeInTheDocument();
	});

	it('does not render the header wrapper when headerRight is false', () => {
		const { container } = render(
			<FormSection headerRight={false}>
				<p>Content</p>
			</FormSection>
		);
		// No title/description and headerRight=false → header should be omitted entirely.
		expect(container.querySelector('h3')).not.toBeInTheDocument();
		expect(container.textContent).toBe('Content');
	});

	it('applies divider classes when divider prop is true', () => {
		const { container } = render(
			<FormSection divider title="Section">
				<p>Content</p>
			</FormSection>
		);
		const wrapper = container.firstChild as HTMLElement;
		expect(wrapper.className).toContain('wcpos:border-b');
		expect(wrapper.className).toContain('wcpos:pb-6');
	});

	it('does not apply divider classes by default', () => {
		const { container } = render(
			<FormSection title="Section">
				<p>Content</p>
			</FormSection>
		);
		const wrapper = container.firstChild as HTMLElement;
		expect(wrapper.className).not.toContain('wcpos:border-b');
	});

	it('applies wcpos:m-0 to the title heading', () => {
		render(
			<FormSection title="Heading">
				<p>Content</p>
			</FormSection>
		);
		const heading = screen.getByText('Heading');
		expect(heading.className).toContain('wcpos:m-0');
	});
});
