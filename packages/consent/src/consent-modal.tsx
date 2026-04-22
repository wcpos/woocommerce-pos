import * as React from 'react';

import { Button, Modal } from '@wcpos/ui';

import { PrivacyInfoModal } from './privacy-info-modal';
import { t } from './translations';

export interface ConsentModalProps {
	open: boolean;
	onDecide: (choice: 'allowed' | 'denied') => void;
	/** A save is in flight — disable the action buttons. */
	busy?: boolean;
	/** The previous save failed — surface a retry message. */
	error?: boolean;
}

/**
 * Auto-opened pop-up asking the user to opt in to anonymous usage data.
 * Rendered on plugins.php (after activation/update) when the user has
 * not yet made a decision. Does not offer a close/dismiss button — the
 * user must explicitly choose.
 */
export function ConsentModal({ open, onDecide, busy = false, error = false }: ConsentModalProps) {
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
				{error && (
					<p
						role="alert"
						className="wcpos:text-sm wcpos:text-red-700 wcpos:mb-3"
					>
						{t('consent.save_error')}
					</p>
				)}
				<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:gap-2 wcpos:flex-wrap">
					<button
						type="button"
						onClick={() => setLearnMoreOpen(true)}
						className="wcpos:underline wcpos:text-wp-admin-theme-color wcpos:cursor-pointer wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:text-sm"
					>
						{t('consent.learn_more')}
					</button>
					<div className="wcpos:flex wcpos:gap-2">
						<Button
							variant="secondary"
							disabled={busy}
							onClick={() => onDecide('denied')}
						>
							{t('consent.deny')}
						</Button>
						<Button
							variant="primary"
							disabled={busy}
							onClick={() => onDecide('allowed')}
						>
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
