import * as React from 'react';

import { render, screen } from '@testing-library/react';

import Label from '../label';

// Mock @wordpress/components
jest.mock('@wordpress/components', () => ({
	Tooltip: ({ text, children }: { text: string; children: React.ReactNode }) => (
		<div data-testid="tooltip" data-tip={text}>
			{children}
		</div>
	),
	Icon: ({ icon, className }: { icon: string; className?: string }) => (
		<span data-testid="icon" data-icon={icon} className={className} />
	),
}));

describe('Label', () => {
	it('renders children', () => {
		render(<Label>Field Name</Label>);
		expect(screen.getByText('Field Name')).toBeInTheDocument();
	});

	it('renders a tooltip when tip is provided', () => {
		render(<Label tip="Help text">Field Name</Label>);
		expect(screen.getByTestId('tooltip')).toHaveAttribute('data-tip', 'Help text');
		expect(screen.getByTestId('icon')).toBeInTheDocument();
	});

	it('does not render a tooltip when tip is omitted', () => {
		render(<Label>Field Name</Label>);
		expect(screen.queryByTestId('tooltip')).not.toBeInTheDocument();
	});
});
