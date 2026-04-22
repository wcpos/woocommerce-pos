import * as React from 'react';
import { createRoot } from 'react-dom/client';

import {
	dismissCallout,
	saveConsent,
	type ConsentChoice,
	type ConsentConfig,
} from './api';
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
	container: HTMLElement;
	initialModal: boolean;
	initialCallout: boolean;
}

function ConsentRoot({ config, container, initialModal, initialCallout }: RootProps) {
	const [modalOpen, setModalOpen] = React.useState(initialModal);
	const [calloutVisible, setCalloutVisible] = React.useState(initialCallout);
	const [isSaving, setIsSaving] = React.useState(false);
	const [hasError, setHasError] = React.useState(false);
	const savingRef = React.useRef(false);

	// The mount container carries the WP-native `.notice` classes so
	// core's header-hoist picks it up. Once the callout is no longer
	// visible (dismissed or decision recorded) hide the empty shell so
	// it doesn't linger as an empty blue bar.
	React.useEffect(() => {
		container.style.display = calloutVisible ? '' : 'none';
	}, [container, calloutVisible]);

	const handleDecide = React.useCallback(
		async (choice: ConsentChoice) => {
			// Single-flight: ignore rapid repeat clicks while a save is in flight.
			if (savingRef.current) {
				return;
			}
			savingRef.current = true;
			setIsSaving(true);
			setHasError(false);

			try {
				await saveConsent(choice, config);
			} catch (err) {
				// Surface the failure in the UI so the modal isn't stuck silently.
				// eslint-disable-next-line no-console
				console.error('[wcpos-consent] failed to save choice', err);
				setHasError(true);
				return;
			} finally {
				savingRef.current = false;
				setIsSaving(false);
			}

			setModalOpen(false);
			setCalloutVisible(false);
		},
		[config]
	);

	const handleDismiss = React.useCallback(() => {
		// Hide locally straight away — the server call is best-effort,
		// and even if it fails the user should see the callout close.
		setCalloutVisible(false);
		void dismissCallout(config).catch((err) => {
			// eslint-disable-next-line no-console
			console.error('[wcpos-consent] failed to persist dismiss', err);
		});
	}, [config]);

	return (
		<>
			{calloutVisible && (
				<ConsentCallout
					onDecide={handleDecide}
					onDismiss={handleDismiss}
					busy={isSaving}
					error={hasError}
				/>
			)}
			<ConsentModal
				open={modalOpen}
				onDecide={handleDecide}
				busy={isSaving}
				error={hasError}
			/>
		</>
	);
}

function mount(): void {
	const config = window.wcpos?.consent;
	if (!config || !config.restUrl || !config.dismissUrl || !config.nonce) {
		return;
	}

	const container = document.getElementById('wcpos-consent-root');
	if (!container) {
		return;
	}

	const showModal = Boolean(config.showModal);
	const showCallout = Boolean(config.showCallout);
	if (!showModal && !showCallout) {
		container.style.display = 'none';
		return;
	}

	// If the modal is showing but the callout is not, collapse the
	// empty notice shell so the Plugins/Dashboard layout isn't
	// polluted with a blank blue bar.
	if (!showCallout) {
		container.style.display = 'none';
	}

	createRoot(container).render(
		<ConsentRoot
			config={{
				restUrl: config.restUrl,
				dismissUrl: config.dismissUrl,
				nonce: config.nonce,
			}}
			container={container}
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

