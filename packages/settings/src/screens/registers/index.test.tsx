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
vi.mock('../../translations', () => ({ t: (_key: string, fallback?: string) => fallback ?? _key }));

const register = {
	id: '11111111-1111-4111-8111-111111111111',
	name: 'Front desk',
	created_at_gmt: '2026-09-11T09:00:00Z',
	counters_started_at_gmt: null,
	default_float: '50.0000',
	store_id: null,
};

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

describe('Registers (Free, one register)', () => {
	it('catalogs the one-register line', () => {
		expect(en).toMatchObject({
			'registers.one_register': 'One register per site. Two devices share it; more registers need WCPOS Pro.',
		});
	});

	it('shows one register card with no list, store, status or add controls, and the Pro line', async () => {
		apiFetchMock.mockResolvedValue([register]);
		renderScreen();

		expect(await screen.findByTestId(`register-${register.id}`)).toBeInTheDocument();
		expect(apiFetchMock).toHaveBeenCalledWith({
			path: '/wcpos/v2/registers',
			method: 'GET',
			headers: { 'X-WCPOS': '1' },
		});
		expect(screen.getByDisplayValue('Front desk')).toBeInTheDocument();
		expect(screen.getByDisplayValue('50.0000')).toBeInTheDocument();
		expect(screen.queryByRole('table')).not.toBeInTheDocument();
		expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
		expect(screen.queryByRole('button', { name: 'Add register' })).not.toBeInTheDocument();
		expect(screen.queryByRole('button', { name: 'Retire' })).not.toBeInTheDocument();
		expect(
			screen.getByText(/One register per site\. Two devices share it; more registers need WCPOS Pro\./)
		).toBeInTheDocument();
		expect(screen.getByRole('link', { name: 'common.upgrade_to_pro' })).toHaveAttribute(
			'href',
			'https://wcpos.com/pro'
		);
	});

	it('PATCHes a renamed register and refreshes it', async () => {
		apiFetchMock
			.mockResolvedValueOnce([register])
			.mockResolvedValueOnce({ ...register, name: 'Back desk' })
			.mockResolvedValue([{ ...register, name: 'Back desk' }]);
		renderScreen();

		const name = await screen.findByDisplayValue('Front desk');
		fireEvent.change(name, { target: { value: 'Back desk' } });
		fireEvent.blur(name);

		await waitFor(() =>
			expect(apiFetchMock).toHaveBeenCalledWith({
				path: `/wcpos/v2/registers/${register.id}`,
				method: 'PATCH',
				headers: { 'X-WCPOS': '1' },
				data: { name: 'Back desk' },
			})
		);
		expect(await screen.findByDisplayValue('Back desk')).toBeInTheDocument();
	});

	it('shows the server message when a save fails', async () => {
		apiFetchMock.mockResolvedValueOnce([register]).mockRejectedValueOnce(new Error('Register could not be saved.'));
		renderScreen();

		const float = await screen.findByDisplayValue('50.0000');
		fireEvent.change(float, { target: { value: '20' } });
		fireEvent.blur(float);

		expect(await screen.findByText('Register could not be saved.')).toBeInTheDocument();
	});

	it('renders the component Pro registers in place of the whole screen body', async () => {
		const getComponent = vi.fn((key: string) =>
			key === 'registers.screen' ? () => <div data-testid="pro-registers">Pro registers</div> : undefined
		);
		window.wcpos = { settings: { getComponent } as unknown as WcposSettings };
		apiFetchMock.mockResolvedValue([register]);
		renderScreen();

		expect(await screen.findByTestId('pro-registers')).toBeInTheDocument();
		expect(getComponent).toHaveBeenCalledWith('registers.screen');
		expect(screen.queryByTestId(`register-${register.id}`)).not.toBeInTheDocument();
		expect(screen.queryByText(/One register per site/)).not.toBeInTheDocument();
		expect(apiFetchMock).not.toHaveBeenCalled();
	});
});
