import * as React from 'react';

import { Button } from '@wcpos/ui';

import { PrivacyInfoModal } from './privacy-info-modal';
import { t } from './translations';

export interface ConsentCalloutProps {
	onDecide: (choice: 'allowed' | 'denied') => void;
	/** Fired when the user clicks the "X" dismiss button ("hide for now"). */
	onDismiss: () => void;
	/** A save is in flight — disable the action buttons. */
	busy?: boolean;
	/** The previous save failed — surface a retry message. */
	error?: boolean;
}

/**
 * Inline callout shown on the Plugins screen and Dashboard while the
 * user has not yet made a decision about anonymous usage data.
 *
 * Renders into the WP-native `.notice.notice-info.is-dismissible`
 * mount point so the callout inherits core admin-notice width, margins,
 * and placement (beneath the page H1 alongside other admin notices).
 *
 * Three possible user actions:
 *  - "Allow" / "No thanks" → records a consent decision; the callout
 *    disappears permanently.
 *  - The "×" dismiss button → "hide for now": tracking_consent stays
 *    `undecided`; the server stores a per-user timestamp so the
 *    callout re-surfaces after a TTL (or on next plugin activation).
 */
export function ConsentCallout({
	onDecide,
	onDismiss,
	busy = false,
	error = false,
}: ConsentCalloutProps) {
	const [learnMoreOpen, setLearnMoreOpen] = React.useState(false);

	return (
		<>
			<button
				type="button"
				className="notice-dismiss"
				onClick={onDismiss}
			>
				<span className="screen-reader-text">
					{t('consent.dismiss_notice')}
				</span>
			</button>
			<div className="wcpos:py-1">
				<p className="wcpos:font-semibold wcpos:mb-1 wcpos:mt-0">
					{t('consent.callout_title')}
				</p>
				<p className="wcpos:text-sm wcpos:mt-0 wcpos:mb-2">
					{t('consent.callout_body')}{' '}
					<button
						type="button"
						onClick={() => setLearnMoreOpen(true)}
						className="wcpos:underline wcpos:cursor-pointer wcpos:bg-transparent wcpos:border-0 wcpos:p-0"
					>
						{t('consent.learn_more')}
					</button>
				</p>
				{error && (
					<p role="alert" className="wcpos:text-sm wcpos:mt-0 wcpos:mb-2">
						{t('consent.save_error')}
					</p>
				)}
				<div className="wcpos:flex wcpos:gap-2 wcpos:mb-1">
					<Button
						variant="primary"
						disabled={busy}
						onClick={() => onDecide('allowed')}
					>
						{t('consent.allow')}
					</Button>
					<Button
						variant="secondary"
						disabled={busy}
						onClick={() => onDecide('denied')}
					>
						{t('consent.deny')}
					</Button>
				</div>
			</div>
			<PrivacyInfoModal
				open={learnMoreOpen}
				onClose={() => setLearnMoreOpen(false)}
			/>
		</>
	);
}

export default ConsentCallout;
