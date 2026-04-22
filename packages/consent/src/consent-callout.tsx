import * as React from 'react';

import { Button, Notice } from '@wcpos/ui';

import { PrivacyInfoModal } from './privacy-info-modal';
import { t } from './translations';

export interface ConsentCalloutProps {
	onDecide: (choice: 'allowed' | 'denied') => void;
}

/**
 * Inline dismissible callout shown on the Plugins screen and Dashboard
 * while the user has not yet made a decision about anonymous usage data.
 *
 * Denying by pressing "No thanks" counts as a decision and the callout
 * (and future callouts) will stop showing. There is no standalone
 * "dismiss for now" action — we want one of the two explicit choices.
 */
export function ConsentCallout({ onDecide }: ConsentCalloutProps) {
	const [learnMoreOpen, setLearnMoreOpen] = React.useState(false);

	return (
		<>
			<Notice
				status="info"
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
					</div>
					<div className="wcpos:flex wcpos:gap-2">
						<Button variant="primary" onClick={() => onDecide('allowed')}>
							{t('consent.allow')}
						</Button>
						<Button variant="secondary" onClick={() => onDecide('denied')}>
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
