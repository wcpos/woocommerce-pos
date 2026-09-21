import * as React from 'react';

import { addQueryArgs } from '@wordpress/url';

import { Button } from '../../components/ui';
import useNotices from '../../hooks/use-notices';
import { t } from '../../translations';

/**
 * The response is an attachment, so the browser downloads it without navigating
 * and fires no event this page can observe — there is no honest way to know when
 * the file has finished. So the pending label is a brief acknowledgement of the
 * click, not a progress indicator, and it clears itself. Leaving it latched would
 * strand the button on "Downloading…" until the merchant reloaded the screen.
 */
const PENDING_FEEDBACK_MS = 3000;

export default function ExportClosures() {
	const [pending, setPending] = React.useState(false);
	const { setNotice } = useNotices();
	const pendingTimer = React.useRef<ReturnType<typeof setTimeout>>();

	React.useEffect(() => () => clearTimeout(pendingTimer.current), []);

	const download = () => {
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
		clearTimeout(pendingTimer.current);
		pendingTimer.current = setTimeout(() => setPending(false), PENDING_FEEDBACK_MS);
		window.location.assign(url);
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
