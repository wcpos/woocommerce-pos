import * as React from 'react';

import { render, screen, fireEvent } from '@testing-library/react';

import { Button } from '../button';

describe('Button', () => {
	it('renders children', () => {
		render(<Button>Click me</Button>);
		expect(screen.getByRole('button')).toHaveTextContent('Click me');
	});

	it('calls onClick handler', () => {
		const onClick = vi.fn();
		render(<Button onClick={onClick}>Click</Button>);
		fireEvent.click(screen.getByRole('button'));
		expect(onClick).toHaveBeenCalledOnce();
	});

	it('disables when disabled prop is true', () => {
		render(<Button disabled>Click</Button>);
		expect(screen.getByRole('button')).toBeDisabled();
	});

	it('disables when loading prop is true', () => {
		render(<Button loading>Saving</Button>);
		expect(screen.getByRole('button')).toBeDisabled();
	});

	it('shows a spinner when loading', () => {
		const { container } = render(<Button loading>Saving</Button>);
		const svg = container.querySelector('svg');
		expect(svg).toBeInTheDocument();
	});

	it('does not show a spinner when not loading', () => {
		const { container } = render(<Button>Save</Button>);
		const svg = container.querySelector('svg');
		expect(svg).not.toBeInTheDocument();
	});

	it('does not call onClick when disabled', () => {
		const onClick = vi.fn();
		render(
			<Button disabled onClick={onClick}>
				Click
			</Button>
		);
		fireEvent.click(screen.getByRole('button'));
		expect(onClick).not.toHaveBeenCalled();
	});

	it('applies primary variant class', () => {
		render(<Button variant="primary">Primary</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('bg-wp-admin-theme-color');
	});

	it('applies destructive variant class', () => {
		render(<Button variant="destructive">Delete</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('bg-red-600');
	});
});
