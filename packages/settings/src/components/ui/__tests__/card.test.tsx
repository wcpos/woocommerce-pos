import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { Card } from '@wcpos/ui';

describe('Card', () => {
	it('renders children', () => {
		render(<Card>content</Card>);
		expect(screen.getByText('content')).toBeInTheDocument();
	});

	it('renders Card.Body with default padding', () => {
		render(
			<Card>
				<Card.Body>body</Card.Body>
			</Card>
		);
		const body = screen.getByText('body');
		expect(body.className).toContain('wcpos:p-4');
	});

	it('omits padding on Card.Body when noPadding is set', () => {
		render(
			<Card>
				<Card.Body noPadding>body</Card.Body>
			</Card>
		);
		const body = screen.getByText('body');
		expect(body.className).not.toContain('wcpos:p-4');
	});

	it('renders Card.Footer with gray chrome', () => {
		render(
			<Card>
				<Card.Footer>footer</Card.Footer>
			</Card>
		);
		const footer = screen.getByText('footer');
		expect(footer.className).toContain('wcpos:border-t');
		expect(footer.className).toContain('wcpos:bg-gray-50');
	});

	it('applies the active-state ring when active is true', () => {
		const { container } = render(<Card active>x</Card>);
		const card = container.firstChild as HTMLElement;
		expect(card.className).toContain('wcpos:ring-1');
		expect(card.className).toContain('wcpos:border-wp-admin-theme-color');
	});

	it('uses the default gray border when active is false', () => {
		const { container } = render(<Card>x</Card>);
		const card = container.firstChild as HTMLElement;
		expect(card.className).toContain('wcpos:border-gray-200');
		expect(card.className).not.toContain('wcpos:ring-1');
	});
});
