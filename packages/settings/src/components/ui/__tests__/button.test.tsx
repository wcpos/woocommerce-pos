import * as React from 'react';

import { render, screen, fireEvent } from '@testing-library/react';

import { Button as UiButton } from '@wcpos/ui';

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

	it('uses a pointer cursor when enabled', () => {
		render(<Button>Click</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('wcpos:cursor-pointer');
		expect(button.className).not.toContain('wcpos:cursor-not-allowed');
	});

	it('disables when disabled prop is true', () => {
		render(<Button disabled>Click</Button>);
		const button = screen.getByRole('button');
		expect(button).toBeDisabled();
		expect(button.className).toContain('wcpos:cursor-not-allowed');
		expect(button.className).not.toContain('wcpos:cursor-pointer');
	});

	it('disables when loading prop is true', () => {
		render(<Button loading>Saving</Button>);
		const button = screen.getByRole('button');
		expect(button).toBeDisabled();
		expect(button.className).toContain('wcpos:cursor-not-allowed');
		expect(button.className).not.toContain('wcpos:cursor-pointer');
	});

	it('shows a spinner when loading', () => {
		const { container } = render(<Button loading>Saving</Button>);
		const svg = container.querySelector('svg');
		expect(svg).toBeInTheDocument();
	});

	it('keeps spinner circle geometry', () => {
		const { container } = render(<Button loading>Saving</Button>);
		const circle = container.querySelector('svg circle');
		expect(circle).toBeInTheDocument();
		expect(circle).toHaveAttribute('cx', '12');
		expect(circle).toHaveAttribute('cy', '12');
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
		expect(button.className).toContain('wcpos:bg-wp-admin-theme-color');
	});

	it('applies destructive variant class', () => {
		render(<Button variant="destructive">Delete</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('wcpos:bg-red-600');
	});

	it('keeps danger as a deprecated alias for destructive', () => {
		render(<Button variant="danger">Delete</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('wcpos:bg-red-600');
	});

	it('has type="button" by default', () => {
		render(<Button>Click</Button>);
		expect(screen.getByRole('button')).toHaveAttribute('type', 'button');
	});

	it('accepts custom type prop', () => {
		render(<Button type="submit">Submit</Button>);
		expect(screen.getByRole('button')).toHaveAttribute('type', 'submit');
	});

	it('renders Button from @wcpos/ui', () => {
		render(<UiButton>Package Button</UiButton>);
		expect(screen.getByRole('button')).toHaveTextContent('Package Button');
	});
});
