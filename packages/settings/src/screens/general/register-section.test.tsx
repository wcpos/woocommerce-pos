import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RegisterSection, type RegisterSectionProps } from './register-section';
import Notice from '../../components/notice';
import useNotices, { NoticesProvider } from '../../hooks/use-notices';
import { i18n } from '../../translations';
import en from '../../translations/locales/en/wp-admin-settings.json';

const apiFetchMock = vi.fn();
const capture = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));
vi.mock('../../lib/analytics', () => ({
	captureUpgradeCtaClicked: (...args: unknown[]) => capture(...args),
}));

const register = {
	id: '11111111-1111-4111-8111-111111111111',
	name: 'Front desk',
	default_float: '50.0000',
};
const off = { register_sessions: false, variance_threshold: null, expected_close_time: null };
const on = { register_sessions: true, variance_threshold: '5.00', expected_close_time: '17:30' };

function SharedNotice() {
	const { notice } = useNotices();
	return notice ? <Notice status={notice.type}>{notice.message}</Notice> : null;
}

function renderSection(data: RegisterSectionProps['data'] = on) {
	const mutate = vi.fn();
	const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
	const tree = (next: RegisterSectionProps['data']) => (
		<QueryClientProvider client={client}>
			<NoticesProvider>
				<SharedNotice />
				<RegisterSection data={next} mutate={mutate} />
			</NoticesProvider>
		</QueryClientProvider>
	);
	const view = render(tree(data));
	return {
		...view,
		mutate,
		rerenderWith: (next: RegisterSectionProps['data']) => view.rerender(tree(next)),
	};
}

const swapped = new Map<string, string>();
function swapMessage(key: keyof typeof en, value: string) {
	swapped.set(key, en[key]);
	i18n.addResource('en', 'wp-admin-settings', key, value);
}

beforeEach(() => {
	apiFetchMock.mockReset().mockResolvedValue([register]);
	capture.mockReset();
	window.wcpos = { settings: { currencySymbol: '€' } as WcposSettings };
});
afterEach(() => {
	delete window.wcpos;
	swapped.forEach((value, key) => i18n.addResource('en', 'wp-admin-settings', key, value));
	swapped.clear();
});

describe('General Register section', () => {
	it('shows only the main switch when off, with no register request or inputs', () => {
		renderSection(off);
		expect(screen.getAllByRole('switch')).toHaveLength(1);
		expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
		expect(apiFetchMock).not.toHaveBeenCalled();
	});

	it('shows float, approval and reminder values when on', async () => {
		renderSection();
		expect(await screen.findByDisplayValue('50.00')).toBeInTheDocument();
		expect(screen.getAllByRole('switch')).toHaveLength(3);
		expect(screen.getByDisplayValue('5.00')).toBeInTheDocument();
		expect(screen.getByText(en['settings.ask_manager_help'])).toBeInTheDocument();
		expect(screen.getByText(en['settings.close_reminder_help'])).toBeInTheDocument();
		expect(screen.getByDisplayValue('17:30')).toHaveAttribute('type', 'time');
		expect(apiFetchMock).toHaveBeenCalledWith({
			path: '/wcpos/v2/registers',
			method: 'GET',
			headers: { 'X-WCPOS': '1' },
		});
		expect(screen.queryByDisplayValue('Front desk')).not.toBeInTheDocument();
	});

	it('does not suspend the other rows while registers load', () => {
		apiFetchMock.mockReturnValue(new Promise(() => {}));
		renderSection();
		expect(screen.getAllByRole('switch')).toHaveLength(3);
		expect(screen.getByDisplayValue('5.00')).toBeInTheDocument();
	});

	it.each([
		['Ask a manager when the count is out', 'variance_threshold', '5.00'],
		['Remind the cashier to close', 'expected_close_time', '17:30'],
	])('turns %s on with its default and off with null', (label, key, value) => {
		const first = renderSection({ ...off, register_sessions: true });
		expect(screen.queryByText(en['settings.ask_manager_help'])).not.toBeInTheDocument();
		expect(screen.queryByText(en['settings.close_reminder_help'])).not.toBeInTheDocument();
		fireEvent.click(screen.getByRole('switch', { name: label }));
		expect(first.mutate).toHaveBeenCalledWith({ [key]: value });
		first.unmount();
		const second = renderSection();
		fireEvent.click(screen.getByRole('switch', { name: label }));
		expect(second.mutate).toHaveBeenCalledWith({ [key]: null });
	});

	it('PATCHes a valid float on blur', async () => {
		const updated = { ...register, default_float: '25.50' };
		apiFetchMock
			.mockResolvedValueOnce([register])
			.mockResolvedValueOnce(updated)
			.mockResolvedValue([updated]);
		renderSection();
		const input = await screen.findByDisplayValue('50.00');
		fireEvent.change(input, { target: { value: '25.50' } });
		fireEvent.blur(input);
		await waitFor(() =>
			expect(apiFetchMock).toHaveBeenCalledWith({
				path: `/wcpos/v2/registers/${register.id}`,
				method: 'PATCH',
				headers: { 'X-WCPOS': '1' },
				data: { default_float: '25.50' },
			})
		);
	});

	it.each([null, '0.0000'])('seeds only a null float when switching on (%s)', async (value) => {
		apiFetchMock.mockResolvedValue([{ ...register, default_float: value }]);
		const { mutate } = renderSection(off);
		fireEvent.click(screen.getByRole('switch', { name: 'Open and close the register' }));
		expect(mutate).toHaveBeenCalledWith({ register_sessions: true });
		await waitFor(() => expect(apiFetchMock).toHaveBeenCalled());
		if (value === null) {
			await waitFor(() =>
				expect(apiFetchMock).toHaveBeenCalledWith({
					path: `/wcpos/v2/registers/${register.id}`,
					method: 'PATCH',
					headers: { 'X-WCPOS': '1' },
					data: { default_float: '200.00' },
				})
			);
		} else {
			expect(apiFetchMock).toHaveBeenCalledTimes(1);
		}
	});

	it('never saves blank or invalid fields, but saves valid policy edits', async () => {
		const { mutate } = renderSection();
		const float = await screen.findByDisplayValue('50.00');
		const approval = screen.getByDisplayValue('5.00');
		const reminder = screen.getByDisplayValue('17:30');
		for (const [input, stored] of [
			[float, '50.00'],
			[approval, '5.00'],
			[reminder, '17:30'],
		] as const) {
			fireEvent.change(input, { target: { value: '' } });
			fireEvent.blur(input);
			expect(input).toHaveValue(stored);
		}
		fireEvent.change(approval, { target: { value: '-1' } });
		fireEvent.blur(approval);
		expect(mutate).not.toHaveBeenCalled();
		expect(apiFetchMock).toHaveBeenCalledTimes(1);
		fireEvent.change(approval, { target: { value: '10.00' } });
		fireEvent.blur(approval);
		expect(mutate).toHaveBeenCalledWith({ variance_threshold: '10.00' });
		fireEvent.change(reminder, { target: { value: '18:45' } });
		fireEvent.blur(reminder);
		expect(mutate).toHaveBeenCalledWith({ expected_close_time: '18:45' });
	});

	it('Enter blurs the float and saves it', async () => {
		renderSection();
		const input = await screen.findByDisplayValue('50.00');
		input.focus();
		fireEvent.change(input, { target: { value: '30' } });
		fireEvent.keyDown(input, { key: 'Enter' });
		await waitFor(() =>
			expect(apiFetchMock).toHaveBeenCalledWith(
				expect.objectContaining({
					method: 'PATCH',
					data: { default_float: '30' },
				})
			)
		);
	});

	it('surfaces save errors', async () => {
		apiFetchMock.mockResolvedValueOnce([register]).mockRejectedValueOnce(new Error('Save failed'));
		renderSection();
		const input = await screen.findByDisplayValue('50.00');
		fireEvent.change(input, { target: { value: '20' } });
		fireEvent.blur(input);
		expect(await screen.findByText('Save failed')).toBeInTheDocument();
	});

	it.each([
		['settings.ask_manager_limit', '<amount/> <label>by more than</label>', 'variance_threshold'],
		['settings.close_reminder_time', '<time/> <label>at</label>', 'expected_close_time'],
	] as const)('a translation can put the input before the words (%s)', (key, message, id) => {
		swapMessage(key, message);
		const { container } = renderSection();
		const input = container.querySelector(`input#${id}`)!;
		const label = container.querySelector(`label[for="${id}"]`)!;
		expect(input.closest('.wcpos\\:w-36')?.parentElement).toBe(label.parentElement);
		expect(input.compareDocumentPosition(label) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
	});

	it('the amount input shows the new value when the saved limit changes', () => {
		const { rerenderWith } = renderSection();
		expect(screen.getByLabelText('by more than')).toHaveValue('5.00');
		rerenderWith({ ...on, variance_threshold: '12.50' });
		expect(screen.getByLabelText('by more than')).toHaveValue('12.50');
	});

	it('the amount input keeps focus and typed text when the section rerenders', () => {
		const { rerenderWith } = renderSection();
		const input = screen.getByLabelText('by more than');
		input.focus();
		fireEvent.change(input, { target: { value: '7.25' } });
		rerenderWith({ ...on });
		expect(screen.getByLabelText('by more than')).toBe(input);
		expect(input).toHaveFocus();
		expect(input).toHaveValue('7.25');
	});

	it('the label still points at its input', () => {
		renderSection();
		expect(screen.getByLabelText('by more than')).toHaveAttribute('id', 'variance_threshold');
		expect(screen.getByLabelText('at')).toHaveAttribute('id', 'expected_close_time');
	});

	it('links to Pro and tracks the placement', () => {
		renderSection(off);
		const link = screen.getByRole('link', { name: 'More registers and stores with Pro' });
		expect(link).toHaveAttribute('href', 'https://wcpos.com/pro');
		fireEvent.click(link);
		expect(capture).toHaveBeenCalledWith('general_register', 'https://wcpos.com/pro');
	});
});
