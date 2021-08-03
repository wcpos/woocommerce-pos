import * as React from 'react';
import { TextControl, Button, PanelRow } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

interface LicenseProps {
	hydrate: import('../settings').HydrateProps;
	setNotice: (args: import('../settings').NoticeProps) => void;
}

const License = ({ setNotice }: LicenseProps) => {
	const [email, setEmail] = React.useState('x');
	const [key, setKey] = React.useState('x');

	const handleActivation = React.useCallback(async () => {
		const url = addQueryArgs('https://wcpos.com', {
			request: 'activation',
			'wc-api': 'am-software-api',
			timestamp: Date.now(),
		});

		const data = await apiFetch({
			url,
			method: 'GET',
			mode: 'no-cors',
		}).catch((err) => {
			setNotice({ type: 'error', message: err.message });
		});

		console.log(data);
	}, [setNotice]);

	return (
		<>
			<PanelRow>
				<TextControl label="License Email" value={email} onChange={(value) => setEmail(value)} />
			</PanelRow>
			<PanelRow>
				<TextControl label="License Key" value={key} onChange={(value) => setKey(value)} />
			</PanelRow>
			<PanelRow>
				<Button disabled={!email || !key} isPrimary onClick={handleActivation}>
					Activate
				</Button>
			</PanelRow>
		</>
	);
};

export default License;
