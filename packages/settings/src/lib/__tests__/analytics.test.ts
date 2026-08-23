import {
	captureLicenseActivationFailed,
	captureSettingsSectionViewed,
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

	it('captures the settings section that was opened', () => {
		captureSettingsSectionViewed('checkout');

		expect(window.wcpos.posthog.capture).toHaveBeenCalledWith('settings_section_viewed', {
			section: 'checkout',
		});
	});

	it('ignores an empty section rather than sending a nameless view', () => {
		captureSettingsSectionViewed('');

		expect(window.wcpos.posthog.capture).not.toHaveBeenCalled();
	});

	it('captures upgrade CTA click events', () => {
		captureUpgradeCtaClicked('license_screen_link', 'https://wcpos.com/pro');

		expect(window.wcpos.posthog.capture).toHaveBeenCalledWith('pro_link_clicked', {
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

	it('falls back to server_error when the payload error field is not a string', () => {
		expect(normalizeLicenseActivationFailure({ error: { code: 500 } })).toBe('server_error');
		expect(normalizeLicenseActivationFailure({ message: 42 })).toBe('server_error');
	});
});
