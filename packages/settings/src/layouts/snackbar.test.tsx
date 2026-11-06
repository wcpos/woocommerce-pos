import * as React from 'react';

import { act, fireEvent, render, screen } from '@testing-library/react';

import { Snackbar, SnackbarProvider, useSnackbar } from '@wcpos/ui';

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

	it('drops a pending removal when reused with a new message', () => {
		vi.useFakeTimers();
		const onRemove = vi.fn();
		const { rerender } = render(
			<Snackbar id="save" status="error" message="Couldn't save." dismissible onRemove={onRemove} />
		);

		fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
		rerender(<Snackbar id="save" status="saving" message="Saving…" onRemove={onRemove} />);
		act(() => {
			vi.advanceTimersByTime(300);
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

	it('does not restart auto-dismiss when onRemove changes identity', () => {
		vi.useFakeTimers();
		const first = vi.fn();
		const second = vi.fn();
		const { rerender } = render(
			<Snackbar id="save" status="success" message="Saved." onRemove={first} />
		);

		act(() => {
			vi.advanceTimersByTime(1000);
		});
		rerender(<Snackbar id="save" status="success" message="Saved." onRemove={second} />);
		act(() => {
			vi.advanceTimersByTime(1300);
		});

		expect(first).not.toHaveBeenCalled();
		expect(second).toHaveBeenCalledTimes(1);
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

describe('SnackbarProvider', () => {
	function Harness() {
		const { addSnackbar, removeSnackbar } = useSnackbar();
		return (
			<>
				<button type="button" onClick={() => addSnackbar({ id: 'note', message: 'Hello.' })}>
					add
				</button>
				<button type="button" onClick={() => removeSnackbar('note')}>
					remove
				</button>
			</>
		);
	}

	it('removes a snackbar by id through useSnackbar', () => {
		render(
			<SnackbarProvider>
				<Harness />
			</SnackbarProvider>
		);

		fireEvent.click(screen.getByRole('button', { name: 'add' }));
		expect(screen.getByText('Hello.')).not.toBeNull();

		fireEvent.click(screen.getByRole('button', { name: 'remove' }));
		expect(screen.queryByText('Hello.')).toBeNull();
	});
});
