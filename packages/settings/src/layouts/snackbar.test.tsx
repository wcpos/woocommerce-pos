import * as React from 'react';

import { act, fireEvent, render, screen } from '@testing-library/react';

import { Snackbar } from '@wcpos/ui';

describe('Snackbar', () => {
	afterEach(() => {
		vi.useRealTimers();
	});

	it('renders action buttons and calls onClick', () => {
		const onRetry = vi.fn();
		render(
			<Snackbar
				id="save"
				status="error"
				message="Couldn't save."
				actions={[{ label: 'Retry', onClick: onRetry }]}
			/>
		);

		fireEvent.click(screen.getByRole('button', { name: 'Retry' }));

		expect(onRetry).toHaveBeenCalledTimes(1);
	});

	it('renders a dismiss control that calls onRemove', () => {
		vi.useFakeTimers();
		const onRemove = vi.fn();
		render(
			<Snackbar id="save" status="error" message="Couldn't save." dismissible onRemove={onRemove} />
		);

		fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));

		// Slides out first, like auto-dismiss.
		expect(onRemove).not.toHaveBeenCalled();
		act(() => {
			vi.advanceTimersByTime(300);
		});
		expect(onRemove).toHaveBeenCalledTimes(1);
	});

	it('does not auto-dismiss when actions are given', () => {
		vi.useFakeTimers();
		const onRemove = vi.fn();
		render(
			<Snackbar
				id="save"
				status="error"
				message="Couldn't save."
				actions={[{ label: 'Retry', onClick: vi.fn() }]}
				onRemove={onRemove}
			/>
		);

		act(() => {
			vi.advanceTimersByTime(2500);
		});

		expect(onRemove).not.toHaveBeenCalled();
	});

	it('does not auto-dismiss when dismissible', () => {
		vi.useFakeTimers();
		const onRemove = vi.fn();
		render(
			<Snackbar id="save" status="error" message="Couldn't save." dismissible onRemove={onRemove} />
		);

		act(() => {
			vi.advanceTimersByTime(2500);
		});

		expect(onRemove).not.toHaveBeenCalled();
	});

	it('auto-dismisses with actions when timeout is explicitly true', () => {
		vi.useFakeTimers();
		const onRemove = vi.fn();
		render(
			<Snackbar
				id="save"
				status="success"
				message="Saved."
				actions={[{ label: 'Undo', onClick: vi.fn() }]}
				timeout
				onRemove={onRemove}
			/>
		);

		act(() => {
			vi.advanceTimersByTime(2300);
		});

		expect(onRemove).toHaveBeenCalledTimes(1);
	});

	it('auto-dismisses when actions is empty', () => {
		vi.useFakeTimers();
		const onRemove = vi.fn();
		render(
			<Snackbar id="save" status="success" message="Saved." actions={[]} onRemove={onRemove} />
		);

		act(() => {
			vi.advanceTimersByTime(2300);
		});

		expect(onRemove).toHaveBeenCalledTimes(1);
	});
});
