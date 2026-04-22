import * as React from 'react';

import { Button, Notice } from '@wcpos/ui';

import { PrivacyInfoModal } from './privacy-info-modal';
import { t } from './translations';

export interface ConsentCalloutProps {
	onDecide: (choice: 'allowed' | 'denied') => void;
	/** A save is in flight — disable the action buttons. */
	busy?: boolean;
	/** The previous save failed — surface a retry message. */
	error?: boolean;
}

/**
 * Inline non-dismissible callout shown on the Plugins screen and
 * Dashboard while the user has not yet made a decision about anonymous
 * usage data.
 *
 * The callout requires an explicit choice — pressing "No thanks" counts
 * as denial and hides the callout (and future callouts) permanently.
 * There is no standalone "dismiss for now" action; `isDismissible` on
 * the underlying Notice is false by design.
 */
export function ConsentCallout({
	onDecide,
	busy = false,
	error = false,
}: ConsentCalloutProps) {
	const [learnMoreOpen, setLearnMoreOpen] = React.useState(false);

	return (
		<>
			<Notice
				status={error ? 'error' : 'info'}
				isDismissible={false}
				className="wcpos:my-3"
			>
				<div className="wcpos:flex wcpos:flex-col wcpos:gap-2">
					<div>
						<p className="wcpos:font-semibold wcpos:mb-1">
							{t('consent.callout_title')}
						</p>
						<p className="wcpos:text-sm">
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
							<p role="alert" className="wcpos:text-sm wcpos:mt-2">
								{t('consent.save_error')}
							</p>
						)}
					</div>
					<div className="wcpos:flex wcpos:gap-2">
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
			</Notice>
			<PrivacyInfoModal
				open={learnMoreOpen}
				onClose={() => setLearnMoreOpen(false)}
			/>
		</>
	);
}

export default ConsentCallout;
