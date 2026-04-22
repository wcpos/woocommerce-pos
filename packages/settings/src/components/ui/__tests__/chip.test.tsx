import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { Chip } from '@wcpos/ui';

describe('Chip', () => {
	it('renders neutral by default with pill shape and sm size', () => {
		render(<Chip>Hello</Chip>);
		const chip = screen.getByText('Hello');

		expect(chip.tagName).toBe('SPAN');
		expect(chip.className).toContain('wcpos:bg-gray-100');
		expect(chip.className).toContain('wcpos:text-gray-700');
		expect(chip.className).toContain('wcpos:rounded-full');
		expect(chip.className).toContain('wcpos:text-xs');
	});

	it('applies variant colours', () => {
		render(
			<>
				<Chip variant="info">info</Chip>
				<Chip variant="success">success</Chip>
				<Chip variant="warning">warning</Chip>
				<Chip variant="error">error</Chip>
				<Chip variant="critical">critical</Chip>
				<Chip variant="debug">debug</Chip>
				<Chip variant="brand">brand</Chip>
			</>
		);

		expect(screen.getByText('info').className).toContain('wcpos:bg-blue-50');
		expect(screen.getByText('success').className).toContain('wcpos:bg-green-50');
		expect(screen.getByText('warning').className).toContain('wcpos:bg-amber-50');
		expect(screen.getByText('error').className).toContain('wcpos:bg-red-50');
		expect(screen.getByText('critical').className).toContain('wcpos:bg-red-100');
		expect(screen.getByText('debug').className).toContain('wcpos:bg-gray-100');
		expect(screen.getByText('brand').className).toContain('wcpos:bg-wp-admin-theme-color');
		expect(screen.getByText('brand').className).toContain('wcpos:uppercase');
	});

	it('supports round shape with min width for numeric badges', () => {
		render(<Chip shape="round">3</Chip>);
		const chip = screen.getByText('3');

		expect(chip.className).toContain('wcpos:rounded-full');
		expect(chip.className).toContain('wcpos:min-w-5');
		expect(chip.className).toContain('wcpos:justify-center');
	});

	it('supports xs size for dense contexts', () => {
		render(<Chip size="xs">tiny</Chip>);
		expect(screen.getByText('tiny').className).toContain('wcpos:text-[10px]');
	});

	it('renders a leading icon slot', () => {
		render(
			<Chip variant="success" icon={<svg data-testid="check" />}>
				Active
			</Chip>
		);

		expect(screen.getByTestId('check')).toBeInTheDocument();
		expect(screen.getByText('Active')).toBeInTheDocument();
	});

	it('forwards title attribute for tooltips', () => {
		render(<Chip title="help text">tag</Chip>);
		expect(screen.getByText('tag')).toHaveAttribute('title', 'help text');
	});

	it('forwards arbitrary className after built-ins', () => {
		render(<Chip className="extra-class">tag</Chip>);
		expect(screen.getByText('tag').className).toContain('extra-class');
	});
});
