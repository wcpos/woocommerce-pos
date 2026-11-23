import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ExtensionCard from './extension-card';

import type { Extension } from './index';

interface MutateOptions {
	onError?: (error: Error) => void;
	onSettled?: () => void;
}

const mutate = vi.fn<(data: Record<string, unknown>, options?: MutateOptions) => void>();
vi.mock('../../hooks/use-settings-api', () => ({
	default: () => ({ data: {}, mutate }),
}));

const base: Extension = {
	slug: 'stripe-terminal',
	name: 'Stripe Terminal for WooCommerce',
	description: 'Adds Stripe Terminal support.',
	version: '',
	author: '',
	category: 'payments',
	tags: [],
	requires_wp: '',
	requires_wc: '',
	requires_wcpos: '',
	requires_pro: true,
	icon: '',
	homepage: '',
	download_url: '',
	latest_version: '1.0.0',
	released_at: '',
	status: 'active',
	installed_version: '1.0.0',
	settings_url:
		'https://shop.test/wp-admin/admin.php?page=wc-settings&tab=checkout&section=stripe_terminal',
	gateway_id: 'stripe_terminal',
	pos_enabled: false,
	needs_setup: true,
};

let client: QueryClient;

function renderCard(overrides: Partial<Extension> = {}) {
	return render(
		<QueryClientProvider client={client}>
			<ExtensionCard extension={{ ...base, ...overrides }} />
		</QueryClientProvider>
	);
}

function registerSlots(slots: Record<string, React.ComponentType<{ extension: Extension }>>) {
	window.wcpos = { settings: { getComponent: (key: string) => slots[key] as any } };
}

beforeEach(() => {
	client = new QueryClient();
	mutate.mockReset();
	delete window.wcpos;
});

afterEach(() => {
	delete window.wcpos;
});

// Pro registers the setup action; the setup card shows only alongside it.
const withPro = () => registerSlots({ 'extensions.setup_action': () => null });

describe('Active payment extension chip', () => {
	beforeEach(withPro);

	it.each([
		[{ pos_enabled: false, needs_setup: true }, '2 steps left'],
		[{ pos_enabled: true, needs_setup: true }, '1 step left'],
		[{ pos_enabled: false, needs_setup: false }, '1 step left'],
		[{ pos_enabled: true, needs_setup: false }, 'Ready'],
	])('%o shows "%s"', (state, chip) => {
		renderCard(state);

		expect(screen.getByText(chip)).toBeInTheDocument();
		expect(screen.queryByText('Active')).not.toBeInTheDocument();
	});

	it('says "Check settings" when on and the settings are unknown', () => {
		renderCard({ pos_enabled: true, needs_setup: null });

		// One chip in the header, one link in the footer.
		expect(screen.getAllByText('Check settings')).toHaveLength(2);
		expect(screen.queryByText('Ready')).not.toBeInTheDocument();
	});

	it('keeps the "Update available" chip when an update waits', () => {
		renderCard({ status: 'update_available', has_update: true, pos_enabled: false });

		expect(screen.getByText('Update available')).toBeInTheDocument();
		expect(screen.queryByText('2 steps left')).not.toBeInTheDocument();
	});
});

describe('Active payment extension footer', () => {
	beforeEach(withPro);

	it('links to the settings when they are not finished', () => {
		renderCard({ needs_setup: true });

		expect(screen.getByRole('link', { name: 'Finish settings' })).toHaveAttribute(
			'href',
			base.settings_url
		);
		expect(screen.getByRole('switch', { name: 'Off in the POS' })).not.toBeChecked();
	});

	it('asks the owner to check the settings when the gateway cannot say', () => {
		renderCard({ needs_setup: null });

		expect(screen.getByRole('link', { name: 'Check settings' })).toHaveAttribute(
			'href',
			base.settings_url
		);
		expect(screen.getByText("We can't tell if they're finished.")).toBeInTheDocument();
	});

	it.each(['active', 'update_available'] as const)(
		'says the settings are finished (%s)',
		(status) => {
			renderCard({ status, pos_enabled: true, needs_setup: false });

			expect(screen.getByText('Settings finished')).toBeInTheDocument();
			expect(screen.queryByRole('link', { name: /settings/i })).not.toBeInTheDocument();
			expect(screen.getByRole('switch', { name: 'On in the POS' })).toBeChecked();
		}
	);

	it('turns the gateway on in the POS and refreshes the extensions', () => {
		const invalidate = vi.spyOn(client, 'invalidateQueries');
		mutate.mockImplementation((_data, options) => options?.onSettled?.());
		renderCard({ pos_enabled: false });

		fireEvent.click(screen.getByRole('switch', { name: 'Off in the POS' }));

		expect(mutate).toHaveBeenCalledWith(
			{ gateways: { stripe_terminal: { enabled: true } } },
			expect.any(Object)
		);
		expect(screen.getByRole('switch', { name: 'On in the POS' })).toBeChecked();
		expect(invalidate).toHaveBeenCalledWith({ queryKey: ['extensions'] });
		expect(screen.queryByText(/Couldn't turn it/)).not.toBeInTheDocument();
	});

	it('switches back off and says so when turning it on fails', () => {
		mutate.mockImplementation((_data, options) => {
			options?.onError?.(new Error('nope'));
			options?.onSettled?.();
		});
		renderCard({ pos_enabled: false });

		fireEvent.click(screen.getByRole('switch', { name: 'Off in the POS' }));

		expect(screen.getByRole('switch', { name: 'Off in the POS' })).not.toBeChecked();
		expect(
			screen.getByText("Couldn't turn it on in the POS. Please try again.")
		).toBeInTheDocument();
	});

	it('switches back on and says so when turning it off fails', () => {
		mutate.mockImplementation((_data, options) => options?.onError?.(new Error('nope')));
		renderCard({ pos_enabled: true });

		fireEvent.click(screen.getByRole('switch', { name: 'On in the POS' }));

		expect(screen.getByRole('switch', { name: 'On in the POS' })).toBeChecked();
		expect(
			screen.getByText("Couldn't turn it off in the POS. Please try again.")
		).toBeInTheDocument();
	});
});

describe('Pro slots', () => {
	it('renders the setup action and menu items, not the generic action', () => {
		registerSlots({
			'extensions.action': () => <button type="button">Generic action</button>,
			'extensions.setup_action': ({ extension }) => (
				<button type="button">Update {extension.slug}</button>
			),
			'extensions.menu_items': () => <span role="menuitem">Deactivate</span>,
		});
		renderCard();

		expect(screen.getByRole('button', { name: 'Update stripe-terminal' })).toBeInTheDocument();
		expect(screen.queryByText('Generic action')).not.toBeInTheDocument();

		fireEvent.click(screen.getByRole('button', { name: 'More actions' }));
		const menu = screen.getByRole('menu');
		expect(within(menu).getByText('Settings')).toBeInTheDocument();
		expect(within(menu).getByRole('menuitem', { name: 'Deactivate' })).toBeInTheDocument();
	});

	it('shows the menu for the menu-items slot alone', () => {
		registerSlots({
			'extensions.setup_action': () => null,
			'extensions.menu_items': () => <span role="menuitem">Deactivate</span>,
		});
		renderCard({ settings_url: undefined });

		fireEvent.click(screen.getByRole('button', { name: 'More actions' }));
		expect(screen.getByRole('menuitem', { name: 'Deactivate' })).toBeInTheDocument();
	});

	it('renders the setup notice as its own row under the footer row, not among the buttons', () => {
		registerSlots({
			'extensions.setup_action': () => <button type="button">Update</button>,
			'extensions.setup_notice': ({ extension }) => <span>Could not update {extension.slug}</span>,
		});
		renderCard();

		const notice = screen.getByText('Could not update stripe-terminal');
		const buttonGroup = screen.getByRole('button', { name: 'Update' }).parentElement as HTMLElement;
		const footerRow = buttonGroup.parentElement as HTMLElement;
		expect(buttonGroup.contains(notice)).toBe(false);
		expect(footerRow.contains(notice)).toBe(false);
		expect(footerRow.parentElement?.contains(notice)).toBe(true);
	});
});

describe('Other cards', () => {
	it('leaves an active non-payment card as it was', () => {
		registerSlots({
			'extensions.action': () => <button type="button">Generic action</button>,
			'extensions.setup_action': () => <button type="button">Setup action</button>,
			'extensions.menu_items': () => <span role="menuitem">Deactivate</span>,
		});
		renderCard({
			category: 'catalog',
			gateway_id: undefined,
			pos_enabled: undefined,
			needs_setup: undefined,
		});

		expect(screen.getByText('Active')).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'Generic action' })).toBeInTheDocument();
		expect(screen.queryByRole('switch')).not.toBeInTheDocument();
		expect(screen.queryByText('Setup action')).not.toBeInTheDocument();

		fireEvent.click(screen.getByRole('button', { name: 'More actions' }));
		expect(within(screen.getByRole('menu')).getByText('Settings')).toBeInTheDocument();
		expect(screen.queryByRole('menuitem', { name: 'Deactivate' })).not.toBeInTheDocument();
	});

	it('leaves an inactive payment card as it was', () => {
		registerSlots({ 'extensions.action': () => <button type="button">Generic action</button> });
		renderCard({ status: 'inactive', gateway_id: undefined });

		expect(screen.getByText('Inactive')).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'Generic action' })).toBeInTheDocument();
		expect(screen.queryByRole('switch')).not.toBeInTheDocument();
	});

	it('leaves an active payment card as it was without Pro', () => {
		renderCard({ pos_enabled: false, needs_setup: true });

		expect(screen.getByText('Active')).toBeInTheDocument();
		expect(screen.queryByText('2 steps left')).not.toBeInTheDocument();
		expect(screen.queryByRole('switch')).not.toBeInTheDocument();
		expect(screen.queryByRole('link', { name: 'Finish settings' })).not.toBeInTheDocument();
	});
});
