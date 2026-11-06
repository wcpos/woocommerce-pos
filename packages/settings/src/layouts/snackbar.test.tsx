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
		const onRemove = vi.fn();
		render(
			<Snackbar id="save" status="error" message="Couldn't save." dismissible onRemove={onRemove} />
		);

		fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));

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
});
