import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { Callout } from '@wcpos/ui';

describe('Callout', () => {
	it('renders info status by default', () => {
		render(<Callout>Body text</Callout>);
		const body = screen.getByText('Body text');
		const root = body.closest('div.wcpos\\:flex.wcpos\\:items-start');

		expect(root).not.toBeNull();
		expect(root!.className).toContain('wcpos:bg-blue-50');
		expect(root!.className).toContain('wcpos:border-l-blue-500');
	});

	it('applies status colours', () => {
		const { rerender } = render(<Callout status="success">x</Callout>);
		let root = screen.getByText('x').closest('div.wcpos\\:flex.wcpos\\:items-start')!;
		expect(root.className).toContain('wcpos:bg-green-50');

		rerender(<Callout status="warning">x</Callout>);
		root = screen.getByText('x').closest('div.wcpos\\:flex.wcpos\\:items-start')!;
		expect(root.className).toContain('wcpos:bg-yellow-50');

		rerender(<Callout status="error">x</Callout>);
		root = screen.getByText('x').closest('div.wcpos\\:flex.wcpos\\:items-start')!;
		expect(root.className).toContain('wcpos:bg-red-50');
	});

	it('renders an optional bold title above the body', () => {
		render(<Callout title="Heads up">Body</Callout>);
		expect(screen.getByText('Heads up').className).toContain('wcpos:font-semibold');
		expect(screen.getByText('Body')).toBeInTheDocument();
	});

	it('renders a default icon based on status', () => {
		render(<Callout status="success">Body</Callout>);
		expect(screen.getByText('✓')).toBeInTheDocument();
	});

	it('honours an explicit icon override', () => {
		render(
			<Callout icon={<svg data-testid="custom-icon" />}>Body</Callout>
		);
		expect(screen.getByTestId('custom-icon')).toBeInTheDocument();
		// Default 'i' icon should not render when overridden
		expect(screen.queryByText('i')).toBeNull();
	});

	it('omits the icon when explicitly set to null', () => {
		render(<Callout icon={null}>Body</Callout>);
		// No default 'i' icon should render
		expect(screen.queryByText('i')).toBeNull();
	});

	it('forwards additional className', () => {
		render(<Callout className="extra-class">Body</Callout>);
		const root = screen.getByText('Body').closest('div.wcpos\\:flex.wcpos\\:items-start');
		expect(root!.className).toContain('extra-class');
	});
});
