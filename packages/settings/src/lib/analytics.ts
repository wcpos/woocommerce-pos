export type LicenseActivationFailureReason = 'invalid_key' | 'expired' | 'network' | 'server_error';

type CaptureProperties = Record<string, unknown>;

type PostHogLike = {
	capture?: (event: string, properties?: CaptureProperties) => void;
};

function getPostHog(): PostHogLike | undefined {
	return (window as any)?.wcpos?.posthog;
}

function captureEvent(event: string, properties: CaptureProperties = {}) {
	getPostHog()?.capture?.(event, properties);
}

export function captureUpgradeCtaViewed(placement: string) {
	captureEvent('upgrade_cta_viewed', { placement });
}

export function captureUpgradeCtaClicked(placement: string, destination: string) {
	captureEvent('pro_link_clicked', { placement, destination });
}

/**
 * Record which settings section the user opened.
 *
 * The section name is the route path, so it always matches what the app
 * actually has — the spec's list predates cloud-print, sessions and
 * extensions. No-ops without consent: window.wcpos.posthog is a stub then.
 */
export function captureSettingsSectionViewed(section: string) {
	if (!section) {
		return;
	}

	captureEvent('settings_section_viewed', { section });
}

export function captureLicenseActivationAttempted() {
	captureEvent('license_activate_attempted');
}

export function captureLicenseActivationSucceeded(licenseTier?: string) {
	const properties = licenseTier ? { license_tier: licenseTier } : {};
	captureEvent('license_activate_succeeded', properties);
}

export function normalizeLicenseActivationFailure(input: unknown): LicenseActivationFailureReason {
	if (input instanceof TypeError) {
		return 'network';
	}

	const rawMessage: unknown =
		typeof input === 'string'
			? input
			: input instanceof Error
				? input.message
				: typeof input === 'object' && input
					? ((input as Record<string, unknown>).error ??
						(input as Record<string, unknown>).message ??
						'')
					: '';

	const normalized = (typeof rawMessage === 'string' ? rawMessage : '').toLowerCase();

	if (
		normalized.includes('network') ||
		normalized.includes('fetch') ||
		normalized.includes('failed to fetch')
	) {
		return 'network';
	}

	if (normalized.includes('expired')) {
		return 'expired';
	}

	if (normalized.includes('invalid')) {
		return 'invalid_key';
	}

	return 'server_error';
}

export function captureLicenseActivationFailed(input: unknown) {
	captureEvent('license_activate_failed', {
		reason: normalizeLicenseActivationFailure(input),
	});
}
