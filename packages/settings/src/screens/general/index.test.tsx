import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import GeneralPage from './index';
import en from '../../translations/locales/en/wp-admin-settings.json';

const calls: string[] = [];
const mutate = vi.fn((patch: Record<string, unknown>) => {
	calls.push(`mutate:${String(patch.tracking_consent)}`);
});
const syncConsent = vi.fn((choice: string) => {
	calls.push(`sync:${choice}`);
});
let settings: Record<string, unknown> = {};

vi.mock('../../hooks/use-settings-api', () => ({
	default: () => ({ data: settings, mutate }),
}));
vi.mock('../../lib/analytics', () => ({
	syncConsent: (choice: string) => syncConsent(choice),
}));
vi.mock('../../translations', () => ({ t: (key: string) => en[key as keyof typeof en] ?? key }));
vi.mock('@wcpos/consent', () => ({ PrivacyInfoModal: () => null }));
vi.mock('./barcode-select', () => ({ default: () => null }));
vi.mock('./user-select', () => ({ default: () => null }));
vi.mock('./cloudflare-callout', () => ({ CloudflareCallout: () => null }));
vi.mock('./register-section', () => ({ RegisterSection: () => null }));
vi.mock('./store-details-block', () => ({ StoreDetailsBlock: () => null }));
vi.mock('./storefront-receipt-section', () => ({ StorefrontReceiptSection: () => null }));
vi.mock('./tax-ids-section', () => ({ TaxIdsSection: () => null }));

function renderGeneral(trackingConsent: 'undecided' | 'allowed' | 'denied') {
	settings = { tracking_consent: trackingConsent, force_ssl: true };
	return render(<GeneralPage />);
}

beforeEach(() => {
	calls.length = 0;
	mutate.mockClear();
	syncConsent.mockClear();
});

describe('General Privacy section', () => {
	it('prompts an undecided admin', () => {
		renderGeneral('undecided');

		const prompt = screen.getByTestId('privacy-undecided-prompt');
		expect(prompt).toHaveTextContent(en['settings.privacy_undecided_prompt']);
	});

	it.each([
		['settings.privacy_undecided_allow', 'allowed'],
		['settings.privacy_undecided_deny', 'denied'],
	] as const)('%s syncs the live client, then saves %s', (key, choice) => {
		renderGeneral('undecided');

		fireEvent.click(screen.getByRole('button', { name: en[key] }));

		expect(calls).toEqual([`sync:${choice}`, `mutate:${choice}`]);
		expect(syncConsent).toHaveBeenCalledWith(choice);
		expect(mutate).toHaveBeenCalledWith({ tracking_consent: choice });
	});

	it.each(['allowed', 'denied'] as const)('renders no prompt when consent is %s', (choice) => {
		renderGeneral(choice);

		expect(screen.queryByTestId('privacy-undecided-prompt')).not.toBeInTheDocument();
		expect(screen.queryByText(en['settings.privacy_undecided_prompt'])).not.toBeInTheDocument();
	});

	it('records nothing on render while undecided', () => {
		renderGeneral('undecided');

		expect(syncConsent).not.toHaveBeenCalled();
		expect(mutate).not.toHaveBeenCalled();
	});
});
