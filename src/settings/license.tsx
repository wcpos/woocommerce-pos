import * as React from 'react';
import { TextControl, Button, PanelRow } from '@wordpress/components';

interface LicenseProps {
	hydrate: import('../settings').HydrateProps;
	setNotice: (args: import('../settings').NoticeProps) => void;
}

const License = ({ setNotice }: LicenseProps) => {
	const [email, setEmail] = React.useState('');
	const [key, setKey] = React.useState('');

	return (
		<>
			<PanelRow>
				<TextControl label="License Email" value={email} onChange={(value) => setEmail(value)} />
			</PanelRow>
			<PanelRow>
				<TextControl label="License Key" value={key} onChange={(value) => setKey(value)} />
			</PanelRow>
			<PanelRow>
				<Button
					disabled={!email || !key}
					isPrimary
					onClick={() => {
						console.log('press');
					}}
				>
					Activate
				</Button>
			</PanelRow>
		</>
	);
};

export default License;
