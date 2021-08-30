import * as React from 'react';
import { Dialog } from '@reach/dialog';
import { XIcon } from '@heroicons/react/solid';
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
		// dispatch
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
			className="rounded-lg"
			initialFocusRef={inputRef}
			aria-label={`${gateway.title} Settings`}
		>
			<h2 className="mt-0 relative">
				{gateway.title}
				<button className="absolute top-0 right-0">
					<XIcon onClick={closeModal} className="w-5 h-5" />
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
			<div className="py-2">
				<label htmlFor="title" className="block mb-1 font-medium text-sm">
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
					className="w-full p-2 rounded border border-gray-300 focus:border-wp-admin-theme-color"
				/>
			</div>
			<div className="py-2">
				<label htmlFor="description" className="block mb-1 font-medium text-sm">
					Description
				</label>
				<textarea
					id="description"
					name="description"
					value={description}
					onChange={handleChange}
					className="w-full h-20 p-2 rounded border border-gray-300 focus:border-wp-admin-theme-color"
				/>
			</div>
			<div className="text-right pt-4">
				<Button background="clear" onClick={closeModal}>
					Cancel
				</Button>
				<Button onClick={handleSave}>Save</Button>
			</div>
		</Dialog>
	);
};

export default GatewayModal;
