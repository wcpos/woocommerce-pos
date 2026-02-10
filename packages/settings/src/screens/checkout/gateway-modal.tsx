import * as React from 'react';

import Notice from '../../components/notice';
import { Button, Modal } from '../../components/ui';
import { t, Trans } from '../../translations';

interface GatewayModalProps {
	gateway: import('./gateways').GatewayProps;
	mutate: (data: any) => void;
	closeModal: () => void;
}

function GatewayModal({ gateway, mutate, closeModal }: GatewayModalProps) {
	const [title, setTitle] = React.useState(gateway.title);
	const [description, setDescription] = React.useState(gateway.description);
	const inputRef = React.useRef();

	const handleSave = () => {
		mutate({
			gateways: {
				[gateway.id]: {
					title,
					description,
				},
			},
		});
		closeModal();
	};

	const handleChange = React.useCallback((event: React.ChangeEvent) => {
		const value = event.target.value;
		const field = event.target.id;

		if (field === 'title') {
			setTitle(value);
		}
		if (field === 'description') {
			setDescription(value);
		}
	}, []);

	return (
		<Modal open onClose={() => closeModal()} title={gateway.title} className="wcpos:max-w-md">
			<Notice status="info" isDismissible={false}>
				<Trans
					i18nKey="checkout.gateway_settings_pos_only"
					components={{
						link: (
							<a href="admin.php?page=wc-settings&tab=checkout" target="_blank" rel="noreferrer" />
						),
					}}
				/>
			</Notice>
			<div className="wcpos:py-2">
				<label htmlFor="title" className="wcpos:block wcpos:mb-1 wcpos:font-medium wcpos:text-sm">
					{t('common.title')}
				</label>
				<input
					// @ts-ignore
					ref={inputRef}
					id="title"
					name="title"
					type="text"
					value={title}
					onChange={handleChange}
					className="wcpos:w-full wcpos:p-2 wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:focus:border-wp-admin-theme-color"
				/>
			</div>
			<div className="wcpos:py-2">
				<label
					htmlFor="description"
					className="wcpos:block wcpos:mb-1 wcpos:font-medium wcpos:text-sm"
				>
					{t('common.description')}
				</label>
				<textarea
					id="description"
					name="description"
					value={description}
					onChange={handleChange}
					className="wcpos:w-full wcpos:h-20 wcpos:p-2 wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:focus:border-wp-admin-theme-color"
				/>
			</div>
			<div className="wcpos:text-right wcpos:pt-4 wcpos:flex wcpos:justify-end wcpos:gap-2">
				<Button onClick={closeModal}>{t('common.cancel')}</Button>
				<Button variant="primary" onClick={handleSave}>
					{t('common.save')}
				</Button>
			</div>
		</Modal>
	);
}

export default GatewayModal;
