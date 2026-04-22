export type ConsentChoice = 'allowed' | 'denied';

export interface ConsentConfig {
	/** REST URL for saving the consent choice. */
	restUrl: string;
	/** REST URL for the "hide for now" dismiss endpoint. */
	dismissUrl: string;
	/** Nonce for authenticating the REST request. */
	nonce: string;
}

/**
 * Persist the user's consent choice via the plugin's consent REST endpoint.
 *
 * Uses window.fetch directly rather than @wordpress/api-fetch so the code
 * works identically on plain wp-admin screens (plugins.php, dashboard)
 * where the @wordpress/api-fetch global may not be configured with the
 * REST nonce.
 */
export async function saveConsent(
	choice: ConsentChoice,
	config: ConsentConfig
): Promise<void> {
	const response = await fetch(config.restUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify({ consent: choice }),
	});

	if (!response.ok) {
		const text = await response.text();
		throw new Error(`Consent save failed (${response.status}): ${text}`);
	}
}

/**
 * Persist a "hide for now" dismissal. The server stores a per-user
 * expiry timestamp; tracking_consent remains 'undecided' so the callout
 * re-appears after the server-side TTL or on the next plugin activation.
 */
export async function dismissCallout(config: ConsentConfig): Promise<void> {
	const response = await fetch(config.dismissUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
	});

	if (!response.ok) {
		const text = await response.text();
		throw new Error(`Consent dismiss failed (${response.status}): ${text}`);
	}
}
