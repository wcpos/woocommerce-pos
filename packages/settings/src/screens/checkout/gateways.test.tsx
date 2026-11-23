import * as React from 'react';

import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Gateways from './gateways';

let settings: Record<string, unknown> = {};
vi.mock('../../hooks/use-settings-api', () => ({
	default: () => ({ data: settings, mutate: vi.fn() }),
}));
vi.mock('../../lib/analytics', () => ({
	captureUpgradeCtaClicked: vi.fn(),
	captureUpgradeCtaViewed: vi.fn(),
}));

function gateway(id: string, order: number, enabled: boolean, needs_setup: boolean | null) {
	return { id, title: `Title ${id}`, order, enabled, needs_setup };
}

function renderGateways(gateways: ReturnType<typeof gateway>[]) {
	settings = {
		pro_enabled: true,
		default_gateway: 'pos_cash',
		gateways: Object.fromEntries(gateways.map((item) => [item.id, item])),
	};
	return render(<Gateways />);
}

function row(id: string) {
	return screen.getByText(`Title ${id}`).closest('tr') as HTMLElement;
}

describe('Gateways settings line', () => {
	it('tells the owner an enabled gateway has unfinished settings and links to them', () => {
		renderGateways([gateway('stripe_terminal', 0, true, true)]);

		expect(within(row('stripe_terminal')).getByText(/Its settings aren't finished\./)).toBeTruthy();
		expect(
			within(row('stripe_terminal')).getByRole('link', { name: 'Open settings' })
		).toHaveAttribute('href', 'admin.php?page=wc-settings&tab=checkout&section=stripe_terminal');
	});

	it.each([
		['switched off', false, true],
		['finished', true, false],
		['unknown', true, null],
	] as const)('says nothing for a gateway that is %s', (_label, enabled, needsSetup) => {
		renderGateways([gateway('sumup', 0, enabled, needsSetup)]);

		expect(within(row('sumup')).queryByText(/Its settings aren't finished\./)).toBeNull();
		expect(within(row('sumup')).queryByRole('link', { name: 'Open settings' })).toBeNull();
	});
});

describe('Gateway ID cell', () => {
	it('caps a long id with an ellipsis and keeps the full id in the title', () => {
		const id = 'authorize_net_tap_to_pay_for_woocommerce';
		renderGateways([gateway(id, 0, true, false)]);

		const cell = within(row(id)).getByText(id);
		expect(cell).toHaveAttribute('title', id);
		expect(cell.className).toContain('wcpos:max-w-44');
		expect(cell.className).toContain('wcpos:truncate');
	});
});
