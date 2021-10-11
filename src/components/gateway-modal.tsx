import * as React from 'react';
import { Dialog } from '@reach/dialog';
import XIcon from '@heroicons/react/solid/XIcon';
import Notice from '../components/notice';
import Button from '../components/button';

interface GatewayModalProps {
	gateway: import('./gateways').GatewayProps;
	dispatch: React.Dispatch<any>;
	closeModal: () => void;
}

const GatewayModal = ({ gateway, dispatch, closeModal }: GatewayModalProps) => {
	const [title, setTitle] = React.useState(gateway.title);
	const [description, setDescription] = React.useState(gateway.description);
	const inputRef = React.useRef();

	const handleSave = () => {
		dispatch({
			type: 'update-gateway',
			payload: {
				id: gateway.id,
				title,
				description,
			},
		});
		closeModal();
	};

	const handleChange = React.useCallback(
		(event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
			const value = event.target.value;
			const field = event.target.id;

			if (field == 'title') {
				setTitle(value);
			}
			if (field == 'description') {
				setDescription(value);
			}
		},
		[]
	);

	return (
		<Dialog
			onDismiss={closeModal}
			className="wcpos-rounded-lg"
			initialFocusRef={inputRef}
			aria-label={`${gateway.title} Settings`}
		>
			<h2 className="wcpos-mt-0 wcpos-relative">
				{gateway.title}
				<button className="wcpos-absolute wcpos-top-0 wcpos-right-0">
					<XIcon onClick={closeModal} className="wcpos-w-5 wcpos-h-5" />
				</button>
			</h2>

			<Notice status="info" isDismissible={false}>
				This will change the settings for the POS only. If you would like to change gateway settings
				for online and POS, please visit the 
				<a href="admin.php?page=wc-settings&amp;tab=checkout" target="_blank">
					WooCommerce Settings
				</a>
				.
			</Notice>
			<div className="wcpos-py-2">
				<label htmlFor="title" className="wcpos-block wcpos-mb-1 wcpos-font-medium wcpos-text-sm">
					Title
				</label>
				<input
					// @ts-ignore
					ref={inputRef}
					id="title"
					name="title"
					type="text"
					value={title}
					onChange={handleChange}
					className="wcpos-w-full wcpos-p-2 wcpos-rounded wcpos-border wcpos-border-gray-300 focus:wcpos-border-wp-admin-theme-color"
				/>
			</div>
			<div className="wcpos-py-2">
				<label htmlFor="description" className="wcpos-block mb-1 wcpos-font-medium wcpos-text-sm">
					Description
				</label>
				<textarea
					id="description"
					name="description"
					value={description}
					onChange={handleChange}
					className="wcpos-w-full wcpos-h-20 wcpos-p-2 wcpos-rounded wcpos-border wcpos-border-gray-300 focus:wcpos-border-wp-admin-theme-color"
				/>
			</div>
			<div className="wcpos-text-right wcpos-pt-4">
				<Button background="clear" onClick={closeModal}>
					Cancel
				</Button>
				<Button onClick={handleSave}>Save</Button>
			</div>
		</Dialog>
	);
};

export default GatewayModal;
