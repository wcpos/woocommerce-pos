import * as React from 'react';
import { TextControl, Button, PanelRow } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs, getAuthority } from '@wordpress/url';
import { get } from 'lodash';

interface LicenseProps {
	hydrate: import('../settings').HydrateProps;
	setNotice: (args: import('../settings').NoticeProps) => void;
}

const truncate = (fullStr: string, strLen = 20, separator = '...') => {
	if (fullStr.length <= strLen) return fullStr;

	separator = separator || '...';

	const sepLen = separator.length,
		charsToShow = strLen - sepLen,
		frontChars = Math.ceil(charsToShow / 2),
		backChars = Math.floor(charsToShow / 2);

	return fullStr.substr(0, frontChars) + separator + fullStr.substr(fullStr.length - backChars);
};

const License = ({ hydrate, setNotice }: LicenseProps) => {
	const [settings, setSettings] = React.useState(get(hydrate, ['settings', 'license']));
	const [key, setKey] = React.useState(settings.key);
	const silent = React.useRef(true);

	const handleActivation = async (deactivate = false) => {
		const url = addQueryArgs('https://wcpos.com', {
			'wc-api': 'am-software-api',
			request: deactivate ? 'deactivation' : 'activation',
			instance: settings.instance,
			api_key: key,
			product_id: settings.product_id,
			platform: getAuthority(get(hydrate, 'homepage')),
			version: get(hydrate, 'pro_version'),
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
			if (deactivate) {
				setKey('');
			} else {
				const confetti = get(window, 'confetti');
				if (confetti) {
					confetti();
				}
			}

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
				// @ts-ignore
				setKey(data.key);
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
			<h3>
				<span style={{ fontSize: '4em' }}>🎉</span> Thank You!
			</h3>
			<p>
				License <code>{truncate(settings.key)}</code> has been activated.
			</p>
			<p>Your support helps fund the ongoing development of WooCommerce POS.</p>
			<Button disabled={!key} isPrimary onClick={() => handleActivation(true)}>
				Deactivate
			</Button>
		</>
	) : (
		<PanelRow>
			<TextControl label="License Key" value={key} onChange={(value) => setKey(value)} />
			<Button disabled={!key} isPrimary onClick={() => handleActivation()}>
				Activate
			</Button>
		</PanelRow>
	);
};

export default License;
