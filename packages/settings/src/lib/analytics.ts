export type LicenseActivationFailureReason = 'invalid_key' | 'expired' | 'network' | 'server_error';

type CaptureProperties = Record<string, unknown>;

type PostHogLike = {
	capture?: (event: string, properties?: CaptureProperties) => void;
	opt_in_capturing?: () => void;
	opt_out_capturing?: () => void;
	reset?: () => void;
};

function getPostHog(): PostHogLike | undefined {
	return (window as any)?.wcpos?.posthog;
}

function captureEvent(event: string, properties: CaptureProperties = {}) {
	getPostHog()?.capture?.(event, properties);
}

/**
 * Make the running PostHog client obey a consent answer given in this page.
 *
 * The client is initialised once, at page load, from the consent state at that
 * moment. Turning the privacy toggle off saves `denied` through the SPA without
 * reloading, so without this the live client keeps capturing for the rest of
 * the session — every route change, every CTA — after the user has explicitly
 * said no. An opt-out has to take effect when it is given, not at next reload.
 *
 * `reset()` on opt-out also drops the stored distinct id, so nothing further is
 * attributed to them.
 */
export function syncConsent(choice: 'allowed' | 'denied') {
	const posthog = getPostHog();

	if (!posthog) {
		return;
	}

	if (choice === 'allowed') {
		posthog.opt_in_capturing?.();

		return;
	}

	posthog.opt_out_capturing?.();
	posthog.reset?.();
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
