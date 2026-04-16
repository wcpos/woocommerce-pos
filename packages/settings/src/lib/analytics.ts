export type LicenseActivationFailureReason =
	| 'invalid_key'
	| 'expired'
	| 'network'
	| 'server_error';

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
	captureEvent('upgrade_cta_clicked', { placement, destination });
}

export function captureLicenseActivationAttempted() {
	captureEvent('license_activate_attempted');
}

export function captureLicenseActivationSucceeded(licenseTier?: string) {
	const properties = licenseTier ? { license_tier: licenseTier } : {};
	captureEvent('license_activate_succeeded', properties);
}

export function normalizeLicenseActivationFailure(
	input: unknown
): LicenseActivationFailureReason {
	if (input instanceof TypeError) {
		return 'network';
	}

	const message =
		typeof input === 'string'
			? input
			: input instanceof Error
				? input.message
				: typeof input === 'object' && input
					? ((input as Record<string, unknown>).error ??
							(input as Record<string, unknown>).message ??
							'') as string
					: '';

	const normalized = message.toLowerCase();

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
