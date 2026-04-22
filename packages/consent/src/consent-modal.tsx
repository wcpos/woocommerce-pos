import * as React from 'react';

import { Button, Modal } from '@wcpos/ui';

import { PrivacyInfoModal } from './privacy-info-modal';
import { t } from './translations';

export interface ConsentModalProps {
	open: boolean;
	onDecide: (choice: 'allowed' | 'denied') => void;
}

/**
 * Auto-opened pop-up asking the user to opt in to anonymous usage data.
 * Rendered on plugins.php (after activation/update) when the user has
 * not yet made a decision. Does not offer a close/dismiss button — the
 * user must explicitly choose.
 */
export function ConsentModal({ open, onDecide }: ConsentModalProps) {
	const [learnMoreOpen, setLearnMoreOpen] = React.useState(false);

	return (
		<>
			<Modal
				open={open}
				// User must make a decision — ignore backdrop/escape closes.
				onClose={() => {}}
				title={t('consent.modal_title')}
			>
				<p className="wcpos:text-sm wcpos:text-gray-700 wcpos:mb-4">
					{t('consent.modal_intro')}
				</p>
				<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:gap-2 wcpos:flex-wrap">
					<button
						type="button"
						onClick={() => setLearnMoreOpen(true)}
						className="wcpos:underline wcpos:text-wp-admin-theme-color wcpos:cursor-pointer wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:text-sm"
					>
						{t('consent.learn_more')}
					</button>
					<div className="wcpos:flex wcpos:gap-2">
						<Button variant="secondary" onClick={() => onDecide('denied')}>
							{t('consent.deny')}
						</Button>
						<Button variant="primary" onClick={() => onDecide('allowed')}>
							{t('consent.allow')}
						</Button>
					</div>
				</div>
			</Modal>
			<PrivacyInfoModal
				open={learnMoreOpen}
				onClose={() => setLearnMoreOpen(false)}
			/>
		</>
	);
}

export default ConsentModal;
