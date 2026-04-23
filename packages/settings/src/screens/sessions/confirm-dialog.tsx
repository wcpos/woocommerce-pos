import * as React from 'react';

import { Button, Modal } from '../../components/ui';
import { t } from '../../translations';

interface ConfirmDialogProps {
	open: boolean;
	title: string;
	description: string;
	confirmLabel?: string;
	cancelLabel?: string;
	isSubmitting?: boolean;
	onConfirm: () => void;
	onClose: (value?: boolean) => void;
}

function ConfirmDialog({
	open,
	title,
	description,
	confirmLabel,
	cancelLabel,
	isSubmitting = false,
	onConfirm,
	onClose,
}: ConfirmDialogProps) {
	return (
		<Modal open={open} onClose={onClose} title={title} description={description}>
			<div className="wcpos:mt-4 wcpos:flex wcpos:justify-end wcpos:gap-2">
				<Button
					variant="secondary"
					onClick={() => {
						onClose(false);
					}}
					disabled={isSubmitting}
				>
					{cancelLabel || t('sessions.cancel')}
				</Button>
				<Button variant="destructive" onClick={onConfirm} disabled={isSubmitting}>
					{confirmLabel || t('sessions.confirm')}
				</Button>
			</div>
		</Modal>
	);
}

export default ConfirmDialog;
