export type ConsentChoice = 'allowed' | 'denied';

export interface ConsentConfig {
	/** REST URL for saving the consent choice. */
	restUrl: string;
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
