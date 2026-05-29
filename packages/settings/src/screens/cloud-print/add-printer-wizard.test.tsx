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

		fireEvent.click(screen.getByTestId('wizard-continue')); // star default -> step 1
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
