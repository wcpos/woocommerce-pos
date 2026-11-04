import {
	captureLicenseActivationFailed,
	captureSettingsSectionViewed,
	syncConsent,
	captureUpgradeCtaClicked,
	captureUpgradeCtaViewed,
	normalizeLicenseActivationFailure,
} from '../analytics';

function createPostHogStub() {
	return {
		capture: vi.fn(),
		opt_in_capturing: vi.fn(),
		opt_out_capturing: vi.fn(),
		reset: vi.fn(),
	};
}

describe('settings analytics helper', () => {
	let posthog: ReturnType<typeof createPostHogStub>;

	beforeEach(() => {
		posthog = createPostHogStub();
		window.wcpos = { posthog };
	});

	it('captures upgrade CTA view events', () => {
		captureUpgradeCtaViewed('checkout_gateways');

		expect(posthog.capture).toHaveBeenCalledWith('upgrade_cta_viewed', {
			placement: 'checkout_gateways',
		});
	});

	it('captures the settings section that was opened', () => {
		captureSettingsSectionViewed('checkout');

		expect(posthog.capture).toHaveBeenCalledWith('settings_section_viewed', {
			section: 'checkout',
		});
	});

	it('ignores an empty section rather than sending a nameless view', () => {
		captureSettingsSectionViewed('');

		expect(posthog.capture).not.toHaveBeenCalled();
	});

	it('stops the live client capturing the moment consent is withdrawn', () => {
		syncConsent('denied');

		expect(posthog.opt_out_capturing).toHaveBeenCalled();
		// Drops the stored identity too, so nothing further is attributed.
		expect(posthog.reset).toHaveBeenCalled();
		expect(posthog.opt_in_capturing).not.toHaveBeenCalled();
	});

	it('re-enables the live client when consent is granted', () => {
		syncConsent('allowed');

		expect(posthog.opt_in_capturing).toHaveBeenCalled();
		expect(posthog.opt_out_capturing).not.toHaveBeenCalled();
	});

	it('captures upgrade CTA click events', () => {
		captureUpgradeCtaClicked('license_screen_link', 'https://wcpos.com/pro');

		expect(posthog.capture).toHaveBeenCalledWith('pro_link_clicked', {
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

		expect(posthog.capture).toHaveBeenCalledWith('license_activate_failed', {
			reason: 'network',
		});
	});

	it('falls back to server_error when the payload error field is not a string', () => {
		expect(normalizeLicenseActivationFailure({ error: { code: 500 } })).toBe('server_error');
		expect(normalizeLicenseActivationFailure({ message: 42 })).toBe('server_error');
	});
});
