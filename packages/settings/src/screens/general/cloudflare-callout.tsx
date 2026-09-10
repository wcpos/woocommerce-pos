import * as React from 'react';

import { Callout } from '@wcpos/ui';

import { t } from '../../translations';

const CLOUDFLARE_DOCS_URL = 'https://docs.wcpos.com/support/troubleshooting/cloudflare';

export function CloudflareCallout() {
	const cloudflare = window.wcpos?.settings?.environment?.cloudflare;
	if (cloudflare?.proxied !== true) {
		return null;
	}

	return (
		<div data-testid="cloudflare-callout" className="wcpos:mb-4">
			<Callout status="info" title={t('cloudflare.title', 'Your store is behind Cloudflare')}>
				<p>
					{t(
						'cloudflare.body',
						"Cloudflare's security checks (Bot Fight Mode, Under Attack Mode and WAF challenges) can stop the WCPOS app from reaching your store's REST API. If the app reports \"This store's hosting setup is blocking the app\" (HOST121) or opens a security-check window, add a WAF Skip rule for /wp-json/ in your Cloudflare dashboard."
					)}
				</p>
				{cloudflare.plugin_active === true && (
					<p>
						{t(
							'cloudflare.plugin_note',
							'The Cloudflare WordPress plugin does not manage these rules. They live in the Cloudflare dashboard.'
						)}
					</p>
				)}
				<a
					href={CLOUDFLARE_DOCS_URL}
					target="_blank"
					rel="noreferrer"
					className="wcpos:text-wp-admin-theme-color wcpos:underline"
				>
					{t('cloudflare.docs_link', 'How to add the rule')}
				</a>
			</Callout>
		</div>
	);
}
