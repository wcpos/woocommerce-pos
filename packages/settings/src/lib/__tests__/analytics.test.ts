import {
	captureLicenseActivationFailed,
	captureUpgradeCtaClicked,
	captureUpgradeCtaViewed,
	normalizeLicenseActivationFailure,
} from '../analytics';

describe('settings analytics helper', () => {
	beforeEach(() => {
		(window as any).wcpos = {
			posthog: {
				capture: vi.fn(),
			},
		};
	});

	it('captures upgrade CTA view events', () => {
		captureUpgradeCtaViewed('checkout_gateways');

		expect(window.wcpos.posthog.capture).toHaveBeenCalledWith('upgrade_cta_viewed', {
			placement: 'checkout_gateways',
		});
	});

	it('captures upgrade CTA click events', () => {
		captureUpgradeCtaClicked('license_screen_link', 'https://wcpos.com/pro');

		expect(window.wcpos.posthog.capture).toHaveBeenCalledWith('upgrade_cta_clicked', {
			placement: 'license_screen_link',
			destination: 'https://wcpos.com/pro',
		});
	});

	it('normalizes expired license failures', () => {
		expect(normalizeLicenseActivationFailure('License key expired')).toBe('expired');
	});

	it('normalizes invalid key failures', () => {
		expect(normalizeLicenseActivationFailure('Invalid license key')).toBe('invalid_key');
	});

	it('normalizes network failures from thrown fetch errors', () => {
		captureLicenseActivationFailed(new TypeError('Failed to fetch'));

		expect(window.wcpos.posthog.capture).toHaveBeenCalledWith('license_activate_failed', {
			reason: 'network',
		});
	});
});
