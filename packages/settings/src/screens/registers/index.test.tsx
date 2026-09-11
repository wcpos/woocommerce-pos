import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import Registers from './index';
import Notice from '../../components/notice';
import useNotices, { NoticesProvider } from '../../hooks/use-notices';
import en from '../../translations/locales/en/wp-admin-settings.json';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));
vi.mock('../../translations', () => ({ t: (_key: string, fallback: string) => fallback }));

const register = {
	id: '11111111-1111-4111-8111-111111111111',
	name: 'Front desk',
	platform: 'web',
	app_version: '1.0.0',
	last_seen_at_gmt: '2026-09-11T10:00:00Z',
	created_at_gmt: '2026-09-11T09:00:00Z',
	counters_started_at_gmt: null,
	default_float: '50.0000',
	status: 'active',
	store_id: null,
};
const storeOptions = [
	{ id: 1, name: 'Main store' },
	{ id: 2, name: 'Second store' },
];

// The root layout renders this shared notice context above the active screen.
function SharedNotice() {
	const { notice } = useNotices();
	return notice ? <Notice status={notice.type}>{notice.message}</Notice> : null;
}

function renderScreen() {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	return render(
		<QueryClientProvider client={client}>
			<NoticesProvider>
				<SharedNotice />
				<Registers />
			</NoticesProvider>
		</QueryClientProvider>
	);
}

beforeEach(() => {
	apiFetchMock.mockReset();
	delete window.wcpos;
});

afterEach(() => {
	delete window.wcpos;
});

describe('Registers store column', () => {
	it('catalogs the register creation actions', () => {
		expect(en).toMatchObject({
			'registers.add': 'Add register',
			'registers.create': 'Create register',
		});
	});

	it('creates a register from the form and refreshes the list', async () => {
		const created = { ...register, id: '22222222-2222-4222-8222-222222222222', name: 'Back desk' };
		apiFetchMock
			.mockResolvedValueOnce([register])
			.mockResolvedValueOnce(created)
			.mockResolvedValue([register, created]);
		renderScreen();
		fireEvent.click(await screen.findByRole('button', { name: 'Add register' }));
		fireEvent.change(screen.getByTestId('new-register-name'), { target: { value: 'Back desk' } });
		fireEvent.change(screen.getByTestId('new-register-float'), { target: { value: '25.00' } });
		fireEvent.click(screen.getByRole('button', { name: 'Create register' }));
		await waitFor(() =>
			expect(apiFetchMock).toHaveBeenCalledWith({
				path: '/wcpos/v2/registers',
				method: 'POST',
				headers: { 'X-WCPOS': '1' },
				data: { name: 'Back desk', default_float: '25.00' },
			})
		);
		expect(await screen.findByTestId(`register-${created.id}`)).toBeInTheDocument();
		expect(screen.getByDisplayValue('Back desk')).toBeInTheDocument();
	});

	it('clears a failed-create notice when retrying successfully', async () => {
		const created = { ...register, id: '22222222-2222-4222-8222-222222222222', name: 'Back desk' };
		apiFetchMock
			.mockResolvedValueOnce([register])
			.mockRejectedValueOnce(new Error('Register could not be saved.'))
			.mockResolvedValueOnce(created)
			.mockResolvedValue([register, created]);
		renderScreen();
		fireEvent.click(await screen.findByRole('button', { name: 'Add register' }));
		fireEvent.change(screen.getByTestId('new-register-name'), { target: { value: 'Back desk' } });
		fireEvent.click(screen.getByRole('button', { name: 'Create register' }));
		expect(await screen.findByText('Register could not be saved.')).toBeInTheDocument();

		fireEvent.click(screen.getByRole('button', { name: 'Create register' }));

		expect(await screen.findByTestId(`register-${created.id}`)).toBeInTheDocument();
		expect(screen.queryByText('Register could not be saved.')).not.toBeInTheDocument();
	});

	it('clears the creation draft when cancelling', async () => {
		apiFetchMock.mockResolvedValue([register]);
		renderScreen();
		fireEvent.click(await screen.findByRole('button', { name: 'Add register' }));
		fireEvent.change(screen.getByTestId('new-register-name'), { target: { value: 'Back desk' } });
		fireEvent.change(screen.getByTestId('new-register-float'), { target: { value: '25.00' } });

		fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
		fireEvent.click(screen.getByRole('button', { name: 'Add register' }));

		expect(screen.getByTestId('new-register-name')).toHaveValue('');
		expect(screen.getByTestId('new-register-float')).toHaveValue('');
	});

	it('keeps the Free table unchanged without store options or assigned rows', async () => {
		apiFetchMock.mockResolvedValue([register]);
		renderScreen();

		await screen.findByRole('table');
		expect(screen.queryByRole('columnheader', { name: 'Store' })).not.toBeInTheDocument();
		expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
		expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual([
			'Name',
			'Platform',
			'Last seen',
			'Default float',
			'Status',
		]);
	});

	it('shows the assigned store after Name and PATCHes a selected store', async () => {
		window.wcpos = { settings: { cloudPrintStoreOptions: storeOptions } };
		apiFetchMock
			.mockResolvedValueOnce([{ ...register, store_id: 1 }])
			.mockResolvedValueOnce({ ...register, store_id: 2 })
			.mockResolvedValue([{ ...register, store_id: 2 }]);
		renderScreen();

		const select = await screen.findByRole('combobox', { name: 'Store' });
		expect(screen.getAllByRole('columnheader')[1]).toHaveTextContent('Store');
		expect(select).toHaveDisplayValue('Main store');
		expect(screen.getByRole('option', { name: /Unassigned/ })).toBeDisabled();
		fireEvent.change(select, { target: { value: '2' } });

		await waitFor(() =>
			expect(apiFetchMock).toHaveBeenCalledWith({
				path: `/wcpos/v2/registers/${register.id}`,
				method: 'PATCH',
				headers: { 'X-WCPOS': '1' },
				data: { store_id: 2 },
			})
		);
		await waitFor(() => expect(select).toHaveDisplayValue('Second store'));
	});

	it('shows the server message when a move is refused with a 409', async () => {
		window.wcpos = { settings: { cloudPrintStoreOptions: storeOptions } };
		apiFetchMock.mockResolvedValueOnce([{ ...register, store_id: 1 }]).mockRejectedValueOnce({
			code: 'register_move_refused',
			message: 'Close the open session first.',
			data: { status: 409 },
		});
		renderScreen();

		const select = await screen.findByRole('combobox', { name: 'Store' });
		fireEvent.change(select, { target: { value: '2' } });

		expect(await screen.findByText('Close the open session first.')).toBeInTheDocument();
		expect(select).toHaveDisplayValue('Main store');
	});

	it.each([null, 99])(
		'shows Unassigned for store_id %s rather than the first store',
		async (id) => {
			window.wcpos = { settings: { cloudPrintStoreOptions: storeOptions } };
			apiFetchMock.mockResolvedValue([{ ...register, store_id: id }]);
			renderScreen();

			const select = await screen.findByRole('combobox', { name: 'Store' });
			expect(select).toHaveDisplayValue('— Unassigned');
		}
	);

	it('shows the column for an assigned row even with only one store option', async () => {
		window.wcpos = { settings: { cloudPrintStoreOptions: [storeOptions[0]] } };
		apiFetchMock.mockResolvedValue([{ ...register, store_id: 1 }]);
		renderScreen();

		expect(await screen.findByRole('combobox', { name: 'Store' })).toHaveDisplayValue('Main store');
	});
});
