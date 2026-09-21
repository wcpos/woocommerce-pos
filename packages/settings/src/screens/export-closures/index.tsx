import * as React from 'react';

import { addQueryArgs } from '@wordpress/url';

import { Button } from '../../components/ui';
import useNotices from '../../hooks/use-notices';
import { t } from '../../translations';

const FALLBACK_FILENAME = 'wcpos-closures.csv';

/** Prefer the server's filename so the download matches what the route named it. */
function filenameFrom(disposition: string | null): string {
	if (!disposition) return FALLBACK_FILENAME;
	const match = /filename="([^"]+)"/.exec(disposition);
	return match?.[1] || FALLBACK_FILENAME;
}

export default function ExportClosures() {
	const [pending, setPending] = React.useState(false);
	const { setNotice } = useNotices();

	const download = async () => {
		const { root, nonce } = window.wpApiSettings ?? {};
		if (!root || !nonce) {
			setNotice({
				type: 'error',
				message: t(
					'export_closures.failed',
					'The download could not be started. Reload this page and try again.'
				),
			});
			return;
		}

		const url = addQueryArgs(`${root}wcpos/v2/closures/export`, {
			wcpos: 1,
			_wpnonce: nonce,
		});

		setNotice(null);
		setPending(true);
		let objectUrl: string | undefined;
		try {
			// Fetched rather than navigated to. A top-level navigation cannot tell a CSV
			// from an error: only a successful export carries Content-Disposition, so a
			// refusal would replace this screen with raw JSON and lose the merchant's
			// place. Fetching keeps failures on the page as a notice.
			const response = await fetch(url, { credentials: 'same-origin' });
			if (!response.ok) {
				setNotice({
					type: 'error',
					message: t(
						'export_closures.refused',
						'The export could not be created. Check that you have permission to view reports, then try again.'
					),
				});
				return;
			}
			objectUrl = URL.createObjectURL(await response.blob());
			const link = document.createElement('a');
			link.href = objectUrl;
			link.download = filenameFrom(response.headers.get('content-disposition'));
			document.body.appendChild(link);
			link.click();
			link.remove();
		} catch {
			setNotice({
				type: 'error',
				message: t(
					'export_closures.failed',
					'The download could not be started. Reload this page and try again.'
				),
			});
		} finally {
			if (objectUrl) URL.revokeObjectURL(objectUrl);
			setPending(false);
		}
	};

	return (
		<div className="wcpos:p-4 wcpos:max-w-2xl">
			<p className="wcpos:mb-4">
				{t(
					'export_closures.description',
					'Download every closure this store has recorded as a CSV file — one row per closure, with its number, register, business day, float, counted and expected totals, variance, tender and tax breakdowns, and running totals. Figures are exported exactly as they were recorded at the time of closing.'
				)}
			</p>
			<Button data-testid="export-closures-download" onClick={download} disabled={pending}>
				{pending
					? t('export_closures.pending', 'Downloading…')
					: t('export_closures.download', 'Download CSV')}
			</Button>
		</div>
	);
}
