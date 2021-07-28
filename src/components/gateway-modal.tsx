import * as React from 'react';
import {
	Button,
	ButtonGroup,
	Modal,
	TextControl,
	TextareaControl,
	Notice,
} from '@wordpress/components';

interface GatewayModalProps {
	gateway: import('./gateways').GatewayProps;
	dispatch: React.Dispatch<any>;
	closeModal: () => void;
}

const GatewayModal = ({ gateway, dispatch, closeModal }: GatewayModalProps) => {
	const [title, setTitle] = React.useState(gateway.title);
	const [description, setDescription] = React.useState(gateway.description);

	const handleSave = () => {
		// dispatch
		closeModal();
	};

	return (
		<Modal
			title={gateway.title}
			onRequestClose={closeModal}
			className="woocommerce-pos-settings-modal"
		>
			<Notice status="warning" isDismissible={false}>
				This will change the settings for the POS only. If you would like to change gateway settings
				for online and POS, please visit the{' '}
				<a href="admin.php?page=wc-settings&amp;tab=checkout" target="_blank">
					WooCommerce Settings
				</a>
				.
			</Notice>
			<TextControl label="Title" value={title} onChange={(value: string) => setTitle(value)} />
			<TextareaControl
				label="Description"
				value={description}
				onChange={(value: string) => setDescription(value)}
			/>
			<div style={{ textAlign: 'right' }}>
				<Button onClick={closeModal}>Cancel</Button>
				<Button isPrimary onClick={handleSave}>
					Save
				</Button>
			</div>
		</Modal>
	);
};

export default GatewayModal;
