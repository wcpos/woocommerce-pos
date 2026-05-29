import * as React from 'react';

import { SnackbarProvider } from '@wcpos/ui';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({
	default: (...args: unknown[]) => apiFetchMock(...args),
}));

import { PrinterCard } from './printer-card';

import type { CloudPrinter } from '../../hooks/use-cloud-print-settings';

function makePrinter(overrides: Partial<CloudPrinter> = {}): CloudPrinter {
	return {
		id: 'kitchen-1',
		name: 'Kitchen',
		provider: 'star-cloudprnt',
		store_id: 0,
		status: 'connected',
		last_seen: null,
		...overrides,
	};
}

function renderCard(props: Partial<React.ComponentProps<typeof PrinterCard>> = {}) {
	const onRename = props.onRename ?? vi.fn();
	const onRemove = props.onRemove ?? vi.fn();
	const onOpenSetup = props.onOpenSetup ?? vi.fn();
	const printer = props.printer ?? makePrinter();
	const utils = render(
		<SnackbarProvider>
			<PrinterCard
				printer={printer}
				onRename={onRename}
				onRemove={onRemove}
				onOpenSetup={onOpenSetup}
			/>
		</SnackbarProvider>
	);
	return { ...utils, onRename, onRemove, onOpenSetup, printer };
}

beforeEach(() => {
	apiFetchMock.mockReset();
});

afterEach(() => {
	vi.useRealTimers();
});

describe('PrinterCard', () => {
	it('renders the provider badge mark and label', () => {
		renderCard({ printer: makePrinter({ provider: 'star-cloudprnt' }) });
		expect(screen.getByText('★')).toBeInTheDocument();
		expect(screen.getByText('Star CloudPRNT')).toBeInTheDocument();
	});

	it('renders a success Chip with "Connected" when status is connected', () => {
		const printer = makePrinter({ status: 'connected' });
		renderCard({ printer });
		const chip = screen.getByTestId(`printer-card-status-${printer.id}`);
		expect(chip).toHaveTextContent('Connected');
	});

	it('renders a warning Chip with "Waiting for printer" when status is waiting', () => {
		const printer = makePrinter({ id: 'p-wait', status: 'waiting' });
		renderCard({ printer });
		const chip = screen.getByTestId(`printer-card-status-${printer.id}`);
		expect(chip).toHaveTextContent('Waiting for printer');
	});

	it('renders an error Chip with "Offline" when status is offline', () => {
		const printer = makePrinter({ id: 'p-off', status: 'offline' });
		renderCard({ printer });
		const chip = screen.getByTestId(`printer-card-status-${printer.id}`);
		expect(chip).toHaveTextContent('Offline');
	});

	it('commits a renamed value on blur', () => {
		const { onRename, printer } = renderCard();
		const input = screen.getByTestId(`printer-card-name-${printer.id}`) as HTMLInputElement;
		fireEvent.change(input, { target: { value: 'New Name' } });
		fireEvent.blur(input);
		expect(onRename).toHaveBeenCalledWith(printer.id, 'New Name');
	});

	it('commits a renamed value on Enter', () => {
		const { onRename, printer } = renderCard();
		const input = screen.getByTestId(`printer-card-name-${printer.id}`) as HTMLInputElement;
		fireEvent.change(input, { target: { value: 'Bar' } });
		fireEvent.keyDown(input, { key: 'Enter' });
		expect(onRename).toHaveBeenCalledWith(printer.id, 'Bar');
	});

	it('does not call onRename when committed value is empty and reverts the field', () => {
		const { onRename, printer } = renderCard();
		const input = screen.getByTestId(`printer-card-name-${printer.id}`) as HTMLInputElement;
		fireEvent.change(input, { target: { value: '   ' } });
		fireEvent.blur(input);
		expect(onRename).not.toHaveBeenCalled();
		expect(input.value).toBe(printer.name);
	});

	it('shows "never" for last check-in when last_seen is null', () => {
		renderCard({ printer: makePrinter({ last_seen: null }) });
		expect(screen.getByText('Last check-in')).toBeInTheDocument();
		expect(screen.getByText('never')).toBeInTheDocument();
	});

	it('shows a relative time for last check-in when last_seen is recent', () => {
		const recent = Math.floor(Date.now() / 1000) - 120; // 2 minutes ago
		renderCard({ printer: makePrinter({ last_seen: recent }) });
		const meta = screen.getByTestId(`printer-card-last-seen-${'kitchen-1'}`);
		expect(meta.textContent).not.toBe('never');
		expect(meta.textContent?.length).toBeGreaterThan(0);
	});

	it('renders the printer id inside a <code> element', () => {
		const printer = makePrinter({ id: 'abc-123' });
		const { container } = renderCard({ printer });
		const code = container.querySelector('code');
		expect(code).not.toBeNull();
		expect(code).toHaveTextContent('abc-123');
	});

	it('exposes the immutability tooltip copy on hover', () => {
		const printer = makePrinter();
		renderCard({ printer });
		const info = screen.getByTestId(`printer-card-id-info-${printer.id}`);
		fireEvent.mouseEnter(info);
		expect(
			screen.getByText(/Created automatically and can't be changed/i)
		).toBeInTheDocument();
	});

	it('calls the test-print endpoint with the right path and data', async () => {
		apiFetchMock.mockResolvedValue({ id: 'job-1' });
		const printer = makePrinter();
		renderCard({ printer });
		fireEvent.click(screen.getByTestId(`printer-card-test-${printer.id}`));
		await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
		expect(apiFetchMock).toHaveBeenCalledWith({
			path: 'wcpos/v1/print-jobs/test?wcpos=1',
			method: 'POST',
			data: { printer_id: printer.id },
		});
	});

	it('shows a success snackbar after a successful test print', async () => {
		apiFetchMock.mockResolvedValue({ id: 'job-1' });
		const printer = makePrinter({ name: 'Kitchen' });
		renderCard({ printer });
		fireEvent.click(screen.getByTestId(`printer-card-test-${printer.id}`));
		expect(await screen.findByText('Sent a test print to Kitchen.')).toBeInTheDocument();
	});

	it('shows a graceful info snackbar (not an error) on a 400 response', async () => {
		apiFetchMock.mockRejectedValue({
			code: 'wcpos_print_job_no_diagnostic',
			message: 'No diagnostic available',
			data: { status: 400 },
		});
		const printer = makePrinter({ provider: 'printnode' });
		renderCard({ printer });
		fireEvent.click(screen.getByTestId(`printer-card-test-${printer.id}`));
		expect(
			await screen.findByText("Test print isn't available for this printer yet.")
		).toBeInTheDocument();
		// The graceful copy is shown rather than the raw error message.
		expect(screen.queryByText('No diagnostic available')).not.toBeInTheDocument();
	});

	it('shows an error snackbar with the message on other failures', async () => {
		apiFetchMock.mockRejectedValue({
			code: 'wcpos_print_job_create_failed',
			message: 'Could not create job',
			data: { status: 500 },
		});
		const printer = makePrinter();
		renderCard({ printer });
		fireEvent.click(screen.getByTestId(`printer-card-test-${printer.id}`));
		expect(await screen.findByText('Could not create job')).toBeInTheDocument();
	});

	it('calls onOpenSetup from the kebab menu', () => {
		const { onOpenSetup, printer } = renderCard();
		fireEvent.click(screen.getByTestId(`printer-card-menu-${printer.id}`));
		fireEvent.click(screen.getByText('Setup & token'));
		expect(onOpenSetup).toHaveBeenCalledWith(printer);
	});

	it('removes the printer only after confirming', () => {
		const { onRemove, printer } = renderCard();
		fireEvent.click(screen.getByTestId(`printer-card-menu-${printer.id}`));
		fireEvent.click(screen.getByText('Remove printer'));
		// Confirm dialog appears; onRemove not called yet.
		expect(onRemove).not.toHaveBeenCalled();
		const dialog = screen.getByText('Remove printer?');
		expect(dialog).toBeInTheDocument();
		// Confirm.
		fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
		expect(onRemove).toHaveBeenCalledWith(printer);
	});
});
