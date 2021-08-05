import * as React from 'react';
import { TextControl, Button, PanelRow } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { get } from 'lodash';

interface LicenseProps {
	hydrate: import('../settings').HydrateProps;
	setNotice: (args: import('../settings').NoticeProps) => void;
}

const License = ({ hydrate, setNotice }: LicenseProps) => {
	const [settings, setSettings] = React.useState(get(hydrate, ['settings', 'license']));
	console.log(settings);
	const [key, setKey] = React.useState('');
	const silent = React.useRef(true);

	const handleActivation = async (deactivate = false) => {
		const url = addQueryArgs('https://wcpos.com', {
			'wc-api': 'am-software-api',
			request: deactivate ? 'deactivation' : 'activation',
			instance: settings.instance,
			api_key: key,
			// email,
			product_id: settings.product_id,
			timestamp: Date.now(),
		});

		const data = await fetch(url, {
			method: 'GET',
			credentials: 'omit',
		})
			.then((response) => response.json())
			.catch((err) => {
				setNotice({ type: 'error', message: err.message });
			});

		if (!data.success) {
			setNotice({ type: 'error', message: data.error });
		} else {
			setSettings({
				...settings,
				key: deactivate ? '' : key,
				activated: data.activated ? true : false,
			});
		}
	};

	React.useEffect(() => {
		async function updateSettings() {
			const data = await apiFetch({
				path: 'wcpos/v1/settings/license?wcpos=1',
				method: 'POST',
				data: settings,
			}).catch((err) => setNotice({ type: 'error', message: err.message }));

			if (data) {
				silent.current = true;
				setSettings(data);
			}
		}

		if (silent.current) {
			silent.current = false;
		} else {
			updateSettings();
		}
	}, [settings, setNotice]);

	return settings.activated ? (
		<>
			<p>Activated!</p>
			<Button disabled={!key} isPrimary onClick={() => handleActivation(true)}>
				Deactivate
			</Button>
		</>
	) : (
		<>
			<PanelRow>
				<TextControl label="License Key" value={key} onChange={(value) => setKey(value)} />
			</PanelRow>
			<PanelRow>
				<Button disabled={!key} isPrimary onClick={() => handleActivation()}>
					Activate
				</Button>
				<Button disabled={!key} isPrimary onClick={() => handleActivation(true)}>
					Deactivate
				</Button>
			</PanelRow>
		</>
	);
};

export default License;
