import * as React from 'react';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { SnackbarProvider } from '@wcpos/ui';

import ExtensionCard from './extension-card';

import type { Extension } from './index';

const apiFetchMock = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({ default: (...args: unknown[]) => apiFetchMock(...args) }));

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
};

interface GatewaySetup {
	enabled: boolean;
	needs_setup: boolean | null;
}

let client: QueryClient;

/**
 * Render a card with its gateway's POS settings already in the `payment-gateways`
 * query, as the route loader leaves them.
 */
function renderCard(
	overrides: Partial<Extension> = {},
	gateway: GatewaySetup = { enabled: false, needs_setup: true }
) {
	client.setQueryData(['payment-gateways'], {
		gateways: { stripe_terminal: { id: 'stripe_terminal', ...gateway } },
	});
	return render(
		<QueryClientProvider client={client}>
			<SnackbarProvider>
				<ExtensionCard extension={{ ...base, ...overrides }} />
			</SnackbarProvider>
		</QueryClientProvider>
	);
}

function registerSlots(slots: Record<string, React.ComponentType<{ extension: Extension }>>) {
	window.wcpos = { settings: { getComponent: (key: string) => slots[key] as any } };
}

/** A POST to the settings API that resolves or rejects when the test says. */
function deferSave() {
	let resolve!: (value: unknown) => void;
	let reject!: (error: Error) => void;
	apiFetchMock.mockImplementation(
		({ method }: { method: string }) =>
			method === 'POST'
				? new Promise((res, rej) => {
						resolve = res;
						reject = rej;
					})
				: new Promise(() => {}) // No background refetch in these tests.
	);
	return {
		resolve: (value: unknown) => act(async () => resolve(value)),
		reject: (error: Error) => act(async () => reject(error)),
	};
}

beforeEach(() => {
	client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } });
	apiFetchMock.mockReset();
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
		[{ enabled: false, needs_setup: true }, '2 steps left'],
		[{ enabled: true, needs_setup: true }, '1 step left'],
		[{ enabled: false, needs_setup: false }, '1 step left'],
		[{ enabled: true, needs_setup: false }, 'Ready'],
	])('%o shows "%s"', (gateway, chip) => {
		renderCard({}, gateway);

		expect(screen.getByText(chip)).toBeInTheDocument();
		expect(screen.queryByText('Active')).not.toBeInTheDocument();
	});

	it('says "Check settings" when on and the settings are unknown', () => {
		renderCard({}, { enabled: true, needs_setup: null });

		// One chip in the header, one link in the footer.
		expect(screen.getAllByText('Check settings')).toHaveLength(2);
		expect(screen.queryByText('Ready')).not.toBeInTheDocument();
	});

	it('keeps the "Update available" chip when an update waits', () => {
		renderCard({ status: 'update_available', has_update: true });

		expect(screen.getByText('Update available')).toBeInTheDocument();
		expect(screen.queryByText('2 steps left')).not.toBeInTheDocument();
	});
});

describe('Active payment extension footer', () => {
	beforeEach(withPro);

	it('links to the settings when they are not finished', () => {
		renderCard({}, { enabled: false, needs_setup: true });

		expect(screen.getByRole('link', { name: 'Finish settings' })).toHaveAttribute(
			'href',
			base.settings_url
		);
		expect(screen.getByRole('switch', { name: 'Off in the POS' })).not.toBeChecked();
	});

	it('asks the owner to check the settings when the gateway cannot say', () => {
		renderCard({}, { enabled: false, needs_setup: null });

		expect(screen.getByRole('link', { name: 'Check settings' })).toHaveAttribute(
			'href',
			base.settings_url
		);
		expect(screen.getByText("We can't tell if they're finished.")).toBeInTheDocument();
	});

	it.each(['active', 'update_available'] as const)(
		'says the settings are finished (%s)',
		(status) => {
			renderCard({ status }, { enabled: true, needs_setup: false });

			expect(screen.getByText('Settings finished')).toBeInTheDocument();
			expect(screen.queryByRole('link', { name: /settings/i })).not.toBeInTheDocument();
			expect(screen.getByRole('switch', { name: 'On in the POS' })).toBeChecked();
		}
	);

	it('turns the gateway on at once, ignores clicks while saving, and keeps it on when saved', async () => {
		const save = deferSave();
		renderCard({}, { enabled: false, needs_setup: true });

		fireEvent.click(screen.getByRole('switch', { name: 'Off in the POS' }));

		const toggle = await screen.findByRole('switch', { name: 'On in the POS' });
		expect(toggle).toBeChecked();
		expect(toggle).toBeDisabled();
		expect(screen.getByText('1 step left')).toBeInTheDocument();

		// A second click while the first save is in flight sends nothing.
		fireEvent.click(toggle);
		const posts = () => apiFetchMock.mock.calls.filter(([args]) => args.method === 'POST');
		expect(posts()).toHaveLength(1);
		expect(posts()[0][0]).toMatchObject({
			path: 'wcpos/v2/settings/payment-gateways?wcpos=1',
			data: { gateways: { stripe_terminal: { enabled: true } } },
		});

		await save.resolve({
			gateways: { stripe_terminal: { id: 'stripe_terminal', enabled: true, needs_setup: true } },
		});

		expect(screen.getByRole('switch', { name: 'On in the POS' })).toBeChecked();
		expect(screen.getByRole('switch', { name: 'On in the POS' })).not.toBeDisabled();
	});

	it('switches back off when turning it on fails', async () => {
		const save = deferSave();
		renderCard({}, { enabled: false, needs_setup: true });

		fireEvent.click(screen.getByRole('switch', { name: 'Off in the POS' }));
		expect(await screen.findByRole('switch', { name: 'On in the POS' })).toBeChecked();

		await save.reject(new Error('Could not save'));

		const toggle = screen.getByRole('switch', { name: 'Off in the POS' });
		expect(toggle).not.toBeChecked();
		expect(toggle).not.toBeDisabled();
		expect(screen.getByText('2 steps left')).toBeInTheDocument();
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
		renderCard({ category: 'catalog', gateway_id: undefined });

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
		renderCard();

		expect(screen.getByText('Active')).toBeInTheDocument();
		expect(screen.queryByText('2 steps left')).not.toBeInTheDocument();
		expect(screen.queryByRole('switch')).not.toBeInTheDocument();
		expect(screen.queryByRole('link', { name: 'Finish settings' })).not.toBeInTheDocument();
	});
});
