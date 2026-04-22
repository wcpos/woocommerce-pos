import * as React from 'react';

import { Button, Modal } from '@wcpos/ui';

import { t } from './translations';

export interface PrivacyInfoModalProps {
	open: boolean;
	onClose: () => void;
}

/**
 * Detail modal explaining exactly what WCPOS collects. Used both
 * by the opt-in callout/modal and by the settings "Learn what's
 * collected" link.
 */
export function PrivacyInfoModal({ open, onClose }: PrivacyInfoModalProps) {
	return (
		<Modal open={open} onClose={onClose} title={t('consent.modal_title')}>
			<p className="wcpos:text-sm wcpos:text-gray-700 wcpos:mb-3">
				{t('consent.modal_intro')}
			</p>
			<p className="wcpos:text-sm wcpos:text-gray-700 wcpos:mb-2">
				{t('consent.modal_includes')}
			</p>
			<ul className="wcpos:text-sm wcpos:text-gray-700 wcpos:mb-4 wcpos:pl-4 wcpos:list-disc wcpos:space-y-2">
				<li>
					<strong>{t('consent.modal_setup_label')}</strong> —{' '}
					{t('consent.modal_setup_desc')}
				</li>
				<li>
					<strong>{t('consent.modal_store_label')}</strong> —{' '}
					{t('consent.modal_store_desc')}
				</li>
				<li>
					<strong>{t('consent.modal_usage_label')}</strong> —{' '}
					{t('consent.modal_usage_desc')}
				</li>
			</ul>
			<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-4">
				{t('consent.modal_exclusions')}
			</p>
			<div className="wcpos:flex wcpos:justify-end">
				<Button onClick={onClose}>{t('consent.dismiss')}</Button>
			</div>
		</Modal>
	);
}

export default PrivacyInfoModal;
