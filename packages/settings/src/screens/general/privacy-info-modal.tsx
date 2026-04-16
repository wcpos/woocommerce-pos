import * as React from 'react';

import { Button, Modal } from '../../components/ui';
import { t } from '../../translations';

interface PrivacyInfoModalProps {
	open: boolean;
	onClose: () => void;
}

function PrivacyInfoModal({ open, onClose }: PrivacyInfoModalProps) {
	return (
		<Modal open={open} onClose={onClose} title={t('settings.privacy_modal_title')}>
			<p className="wcpos:text-sm wcpos:text-gray-700 wcpos:mb-3">
				{t('settings.privacy_modal_intro')}
			</p>
			<p className="wcpos:text-sm wcpos:text-gray-700 wcpos:mb-2">
				{t('settings.privacy_modal_includes')}
			</p>
			<ul className="wcpos:text-sm wcpos:text-gray-700 wcpos:mb-4 wcpos:pl-4 wcpos:list-disc wcpos:space-y-2">
				<li>
					<strong>{t('settings.privacy_modal_setup_label')}</strong> —{' '}
					{t('settings.privacy_modal_setup_desc')}
				</li>
				<li>
					<strong>{t('settings.privacy_modal_store_label')}</strong> —{' '}
					{t('settings.privacy_modal_store_desc')}
				</li>
				<li>
					<strong>{t('settings.privacy_modal_usage_label')}</strong> —{' '}
					{t('settings.privacy_modal_usage_desc')}
				</li>
			</ul>
			<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-4">
				{t('settings.privacy_modal_exclusions')}
			</p>
			<div className="wcpos:flex wcpos:justify-end">
				<Button onClick={onClose}>{t('settings.privacy_modal_close')}</Button>
			</div>
		</Modal>
	);
}

export default PrivacyInfoModal;
