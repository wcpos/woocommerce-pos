import * as React from 'react';
import { createRoot } from 'react-dom/client';

import { saveConsent, type ConsentChoice, type ConsentConfig } from './api';
import { ConsentCallout } from './consent-callout';
import { ConsentModal } from './consent-modal';

import './index.css';

declare global {
	interface Window {
		wcpos?: {
			consent?: ConsentConfig & {
				/** If true, auto-open the pop-up modal on mount. */
				showModal?: boolean;
				/** If true, render the inline callout. */
				showCallout?: boolean;
			};
		};
	}
}

interface RootProps {
	config: ConsentConfig;
	initialModal: boolean;
	initialCallout: boolean;
}

function ConsentRoot({ config, initialModal, initialCallout }: RootProps) {
	const [modalOpen, setModalOpen] = React.useState(initialModal);
	const [calloutVisible, setCalloutVisible] = React.useState(initialCallout);

	const handleDecide = React.useCallback(
		async (choice: ConsentChoice) => {
			try {
				await saveConsent(choice, config);
			} catch (err) {
				// Keep the UI open so the user can retry; log for debugging.
				// eslint-disable-next-line no-console
				console.error('[wcpos-consent] failed to save choice', err);
				return;
			}
			setModalOpen(false);
			setCalloutVisible(false);
		},
		[config]
	);

	return (
		<>
			{calloutVisible && <ConsentCallout onDecide={handleDecide} />}
			<ConsentModal open={modalOpen} onDecide={handleDecide} />
		</>
	);
}

function mount(): void {
	const config = window.wcpos?.consent;
	if (!config || !config.restUrl || !config.nonce) {
		return;
	}

	const container = document.getElementById('wcpos-consent-root');
	if (!container) {
		return;
	}

	const showModal = Boolean(config.showModal);
	const showCallout = Boolean(config.showCallout);
	if (!showModal && !showCallout) {
		return;
	}

	createRoot(container).render(
		<ConsentRoot
			config={{ restUrl: config.restUrl, nonce: config.nonce }}
			initialModal={showModal}
			initialCallout={showCallout}
		/>
	);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount);
} else {
	mount();
}

