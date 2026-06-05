import * as React from 'react';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AddPrinterWizard, type NewPrinterInput } from './add-printer-wizard';

import type { CloudPrinter } from '../../hooks/use-cloud-print-settings';

const writeText = vi.fn();

beforeEach(() => {
	writeText.mockReset();
	Object.defineProperty(navigator, 'clipboard', {
		value: { writeText },
		configurable: true,
		writable: true,
	});
	(window as unknown as { wpApiSettings?: { root?: string } }).wpApiSettings = {
		root: 'https://mystore.com/wp-json/',
	};
});

afterEach(() => {
	delete (window as unknown as { wpApiSettings?: unknown }).wpApiSettings;
});

function makePrinter(overrides: Partial<CloudPrinter> = {}): CloudPrinter {
	return {
		id: 'kitchen',
		name: 'Kitchen printer',
		provider: 'star-cloudprnt',
		store_id: 0,
		...overrides,
	};
}

describe('AddPrinterWizard', () => {
	it('add mode: pre-selects no provider and keeps Continue disabled until one is chosen', () => {
		render(<AddPrinterWizard open mode="add" onClose={vi.fn()} onCreate={vi.fn()} />);

		// No provider should be pre-selected on open.
		expect(screen.getByTestId('provider-choice-star-cloudprnt')).toHaveAttribute('aria-pressed', 'false');
		expect(screen.getByTestId('provider-choice-epson-sdp')).toHaveAttribute('aria-pressed', 'false');
		expect(screen.getByTestId('provider-choice-printnode')).toHaveAttribute('aria-pressed', 'false');

		// Continue is disabled until a provider is selected.
		expect(screen.getByTestId('wizard-continue')).toBeDisabled();

		fireEvent.click(screen.getByTestId('provider-choice-star-cloudprnt'));

		expect(screen.getByTestId('provider-choice-star-cloudprnt')).toHaveAttribute('aria-pressed', 'true');
		expect(screen.getByTestId('wizard-continue')).toBeEnabled();
	});

	it('add mode: walks star/epson polling flow and shows poll URL + token', async () => {
		const onCreate = vi.fn().mockResolvedValue({
			printer: makePrinter({ provider: 'epson-sdp' }),
			token: '9f3a8c21d7b64e0fa1c2e5d8b09a7f6c',
		});
		const onClose = vi.fn();

		render(<AddPrinterWizard open mode="add" onClose={onClose} onCreate={onCreate} />);

		// Step 0: three provider choices.
		expect(screen.getByTestId('provider-choice-star-cloudprnt')).toBeInTheDocument();
		expect(screen.getByTestId('provider-choice-epson-sdp')).toBeInTheDocument();
		expect(screen.getByTestId('provider-choice-printnode')).toBeInTheDocument();

		// Select Epson, then continue.
		fireEvent.click(screen.getByTestId('provider-choice-epson-sdp'));
		fireEvent.click(screen.getByTestId('wizard-continue'));

		// Step 1: name input.
		const nameInput = screen.getByTestId('wizard-name-input');
		expect(nameInput).toBeInTheDocument();
		// Continue disabled until name entered.
		expect(screen.getByTestId('wizard-continue')).toBeDisabled();
		fireEvent.change(nameInput, { target: { value: 'Kitchen printer' } });
		fireEvent.click(screen.getByTestId('wizard-continue'));

		// onCreate called with no id.
		await waitFor(() => expect(onCreate).toHaveBeenCalledTimes(1));
		const arg = onCreate.mock.calls[0][0] as NewPrinterInput & { id?: unknown };
		expect(arg.name).toBe('Kitchen printer');
		expect(arg.provider).toBe('epson-sdp');
		expect(arg.id).toBeUndefined();
		expect('printnode_api_key' in arg).toBe(false);

		// Step 2: poll URL + token + "shown only once" copy.
		const expectedUrl =
			'https://mystore.com/wp-json/wcpos/v1/print-jobs/epson-sdp?printer_id=kitchen&pt=9f3a8c21d7b64e0fa1c2e5d8b09a7f6c';
		await waitFor(() => expect(screen.getByTestId('wizard-poll-url')).toBeInTheDocument());
		expect(screen.getByTestId('wizard-poll-url')).toHaveTextContent(expectedUrl);
		expect(screen.getByTestId('wizard-poll-token')).toHaveTextContent(
			'9f3a8c21d7b64e0fa1c2e5d8b09a7f6c'
		);
		expect(screen.getByText(/shown only once/i)).toBeInTheDocument();

		// Copy the URL.
		fireEvent.click(screen.getByTestId('wizard-copy-url'));
		expect(writeText).toHaveBeenCalledWith(expectedUrl);

		// Done closes.
		fireEvent.click(screen.getByTestId('wizard-done'));
		expect(onClose).toHaveBeenCalled();
	});

	it('add mode: printnode flow collects api key + printer id and shows linked confirmation', async () => {
		const onCreate = vi.fn().mockResolvedValue({
			printer: makePrinter({ id: 'pn-1', provider: 'printnode' }),
		});

		render(<AddPrinterWizard open mode="add" onClose={vi.fn()} onCreate={onCreate} />);

		fireEvent.click(screen.getByTestId('provider-choice-printnode'));
		fireEvent.click(screen.getByTestId('wizard-continue'));

		// Step 1 printnode fields + note.
		fireEvent.change(screen.getByTestId('wizard-name-input'), {
			target: { value: 'Receipt printer' },
		});
		const apiKey = screen.getByTestId('wizard-printnode-api-key');
		const printerId = screen.getByTestId('wizard-printnode-printer-id');
		expect(apiKey).toBeInTheDocument();
		expect(printerId).toBeInTheDocument();
		expect(screen.getByText(/Install the small PrintNode client/i)).toBeInTheDocument();

		fireEvent.change(apiKey, { target: { value: 'secret-key' } });
		fireEvent.change(printerId, { target: { value: '12345' } });
		fireEvent.click(screen.getByTestId('wizard-continue'));

		await waitFor(() => expect(onCreate).toHaveBeenCalledTimes(1));
		const arg = onCreate.mock.calls[0][0] as NewPrinterInput & { id?: unknown };
		expect(arg).toMatchObject({
			name: 'Receipt printer',
			provider: 'printnode',
			printnode_api_key: 'secret-key',
			printnode_printer_id: 12345,
		});
		expect(typeof arg.printnode_printer_id).toBe('number');
		expect(arg.id).toBeUndefined();

		// Step 2: linked confirmation, no URL/token.
		await waitFor(() => expect(screen.getByText(/Linked to PrintNode/)).toBeInTheDocument());
		expect(screen.queryByTestId('wizard-poll-url')).not.toBeInTheDocument();
		expect(screen.queryByTestId('wizard-poll-token')).not.toBeInTheDocument();
	});

	it('add mode: shows a guard notice (no broken URL) when a polling provider returns no token', async () => {
		const onCreate = vi.fn().mockResolvedValue({
			printer: makePrinter({ provider: 'star-cloudprnt' }),
			// token omitted — should never happen per the Phase 1 contract, but guard it.
		});
		render(<AddPrinterWizard open mode="add" onClose={vi.fn()} onCreate={onCreate} />);

		fireEvent.click(screen.getByTestId('provider-choice-star-cloudprnt'));
		fireEvent.click(screen.getByTestId('wizard-continue')); // -> step 1
		fireEvent.change(screen.getByTestId('wizard-name-input'), {
			target: { value: 'Kitchen printer' },
		});
		fireEvent.click(screen.getByTestId('wizard-continue'));

		await waitFor(() => expect(screen.getByText(/no setup token was returned/i)).toBeInTheDocument());
		// No broken poll URL or empty token row.
		expect(screen.queryByTestId('wizard-poll-url')).not.toBeInTheDocument();
		expect(screen.queryByTestId('wizard-poll-token')).not.toBeInTheDocument();
	});

	it('printnode flow: fetch my printers populates a select and picking one fills the printer id', async () => {
		const fetchPrintNodePrinters = vi.fn().mockResolvedValue([
			{ id: 73, name: 'Front Desk', state: 'online' },
			{ id: 88, name: 'Kitchen', state: 'offline' },
		]);
		const onCreate = vi.fn().mockResolvedValue({
			printer: makePrinter({ id: 'pn-1', provider: 'printnode' }),
		});

		render(
			<AddPrinterWizard
				open
				mode="add"
				onClose={vi.fn()}
				onCreate={onCreate}
				fetchPrintNodePrinters={fetchPrintNodePrinters}
			/>
		);

		fireEvent.click(screen.getByTestId('provider-choice-printnode'));
		fireEvent.click(screen.getByTestId('wizard-continue'));
		fireEvent.change(screen.getByTestId('wizard-name-input'), {
			target: { value: 'Receipt printer' },
		});
		fireEvent.change(screen.getByTestId('wizard-printnode-api-key'), {
			target: { value: 'secret-key' },
		});

		fireEvent.click(screen.getByTestId('wizard-printnode-fetch'));
		await waitFor(() => expect(fetchPrintNodePrinters).toHaveBeenCalledWith('secret-key'));

		const select = await screen.findByTestId('wizard-printnode-printer-select');
		fireEvent.change(select, { target: { value: '88' } });

		fireEvent.click(screen.getByTestId('wizard-continue'));
		await waitFor(() => expect(onCreate).toHaveBeenCalledTimes(1));
		expect(onCreate.mock.calls[0][0].printnode_printer_id).toBe(88);
	});

	it('printnode flow: shows a "no printers" message when the account has none', async () => {
		const fetchPrintNodePrinters = vi.fn().mockResolvedValue([]);

		render(
			<AddPrinterWizard
				open
				mode="add"
				onClose={vi.fn()}
				onCreate={vi.fn()}
				fetchPrintNodePrinters={fetchPrintNodePrinters}
			/>
		);

		fireEvent.click(screen.getByTestId('provider-choice-printnode'));
		fireEvent.click(screen.getByTestId('wizard-continue'));
		fireEvent.change(screen.getByTestId('wizard-printnode-api-key'), {
			target: { value: 'valid-key' },
		});
		fireEvent.click(screen.getByTestId('wizard-printnode-fetch'));

		await waitFor(() =>
			expect(screen.getByTestId('wizard-printnode-fetch-error')).toBeInTheDocument()
		);
		expect(screen.getByTestId('wizard-printnode-fetch-error')).toHaveTextContent(/No printers found/i);
		expect(screen.queryByTestId('wizard-printnode-printer-select')).not.toBeInTheDocument();
	});

	it('printnode flow: surfaces an error when fetching the printer list fails', async () => {
		const fetchPrintNodePrinters = vi.fn().mockRejectedValue(new Error('bad key'));

		render(
			<AddPrinterWizard
				open
				mode="add"
				onClose={vi.fn()}
				onCreate={vi.fn()}
				fetchPrintNodePrinters={fetchPrintNodePrinters}
			/>
		);

		fireEvent.click(screen.getByTestId('provider-choice-printnode'));
		fireEvent.click(screen.getByTestId('wizard-continue'));
		fireEvent.change(screen.getByTestId('wizard-printnode-api-key'), {
			target: { value: 'bad' },
		});
		fireEvent.click(screen.getByTestId('wizard-printnode-fetch'));

		await waitFor(() =>
			expect(screen.getByTestId('wizard-printnode-fetch-error')).toBeInTheDocument()
		);
	});

	it('clears provider-scoped state when switching providers', async () => {
		const fetchStarDevices = vi.fn().mockResolvedValue([
			{ id: 'star-1', name: 'Star One', state: 'online' },
		]);

		render(
			<AddPrinterWizard
				open
				mode="add"
				onClose={vi.fn()}
				onCreate={vi.fn()}
				fetchStarDevices={fetchStarDevices}
			/>
		);

		fireEvent.click(screen.getByTestId('provider-choice-star-online'));
		fireEvent.click(screen.getByTestId('wizard-continue'));
		fireEvent.change(screen.getByTestId('wizard-name-input'), {
			target: { value: 'Star printer' },
		});
		fireEvent.change(screen.getByTestId('wizard-star-cloudprnt-url'), {
			target: { value: 'https://eu-device.stario.online/cloudprnt/kilbot' },
		});
		fireEvent.change(screen.getByTestId('wizard-star-api-key'), {
			target: { value: 'STAR-KEY' },
		});
		fireEvent.click(screen.getByTestId('wizard-star-fetch'));
		const starSelect = await screen.findByTestId('wizard-star-device-select');
		fireEvent.change(starSelect, { target: { value: 'star-1' } });
		expect(screen.getByTestId('wizard-star-device-id')).toHaveValue('star-1');

		fireEvent.click(screen.getByTestId('wizard-back'));
		fireEvent.click(screen.getByTestId('provider-choice-printnode'));
		fireEvent.click(screen.getByTestId('wizard-continue'));

		expect(screen.getByTestId('wizard-name-input')).toHaveValue('Star printer');
		expect(screen.getByTestId('wizard-printnode-api-key')).toHaveValue('');
		expect(screen.getByTestId('wizard-printnode-printer-id')).toHaveValue(null);
		expect(screen.queryByTestId('wizard-star-device-select')).not.toBeInTheDocument();
	});

	it('setup mode: opens at step 2 with masked token and does not call onCreate', () => {
		const onCreate = vi.fn();
		render(
			<AddPrinterWizard
				open
				mode="setup"
				setupPrinter={makePrinter()}
				onClose={vi.fn()}
				onCreate={onCreate}
			/>
		);

		// Step 2 directly.
		const url = screen.getByTestId('wizard-poll-url');
		expect(url).toHaveTextContent(
			'https://mystore.com/wp-json/wcpos/v1/print-jobs/cloudprnt?printer_id=kitchen'
		);
		expect(url).toHaveTextContent('pt=');
		// Token masked (no real token, dots present).
		expect(url.textContent).toContain('•');
		expect(screen.queryByTestId('wizard-poll-token')).not.toBeInTheDocument();
		expect(screen.getByText(/can't be displayed again/i)).toBeInTheDocument();

		expect(onCreate).not.toHaveBeenCalled();
	});

	it('surfaces an error and stays on step 1 when onCreate rejects', async () => {
		const onCreate = vi.fn().mockRejectedValue(new Error('boom'));
		render(<AddPrinterWizard open mode="add" onClose={vi.fn()} onCreate={onCreate} />);

		fireEvent.click(screen.getByTestId('provider-choice-star-cloudprnt'));
		fireEvent.click(screen.getByTestId('wizard-continue')); // -> step 1
		fireEvent.change(screen.getByTestId('wizard-name-input'), {
			target: { value: 'Bar printer' },
		});
		fireEvent.click(screen.getByTestId('wizard-continue'));

		await waitFor(() => expect(screen.getByTestId('wizard-error')).toBeInTheDocument());
		// Still on step 1.
		expect(screen.getByTestId('wizard-name-input')).toBeInTheDocument();
		expect(screen.queryByTestId('wizard-poll-url')).not.toBeInTheDocument();
	});

	it('returns null when closed', () => {
		const { container } = render(
			<AddPrinterWizard open={false} mode="add" onClose={vi.fn()} onCreate={vi.fn()} />
		);
		expect(container).toBeEmptyDOMElement();
	});
});
