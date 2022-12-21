import * as React from 'react';

import { addQueryArgs, getAuthority } from '@wordpress/url';
import { get, throttle } from 'lodash';

import Button from '../../components/button';
import FormRow from '../../components/form-row';
import useNotices from '../../hooks/use-notices';
import useSettingsApi from '../../hooks/use-settings-api';

export interface LicenseSettingsProps {
	key: string;
	instance: string;
	product_id: string;
	activated: boolean;
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

const License = () => {
	const { data, mutate } = useSettingsApi('license');
	const { setNotice } = useNotices();
	const [key, setKey] = React.useState(data.key);

	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => setKey(event.target.value);

	const handleActivation = async (deactivate = false) => {
		const url = addQueryArgs('https://wcpos.com', {
			'wc-api': 'am-software-api',
			request: deactivate ? 'deactivation' : 'activation',
			instance: data.instance,
			api_key: key,
			product_id: data.product_id,
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

			mutate({
				key: deactivate ? '' : key,
				activated: !!data.activated,
			});
		}
	};

	return data.activated ? (
		<div className="wcpos-px-4 wcpos-py-5 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4 sm:wcpos-px-6 wcpos-items-center">
			<div className="wcpos-text-right wcpos-text-8xl">🎉</div>
			<div className="wcpos-col-span-2">
				<h3>Thank You!</h3>
				<p>
					License <code>{truncate(data.key)}</code> has been activated.
				</p>
				<p>Your support helps fund the ongoing development of WooCommerce POS.</p>
				<Button onClick={() => handleActivation(true)}>Deactivate</Button>
			</div>
		</div>
	) : (
		<FormRow>
			<FormRow.Label id="license-key" className="wcpos-text-right">
				License Key
			</FormRow.Label>
			<FormRow.Col>
				<input
					type="text"
					name="license-key"
					id="license-key"
					className="wcpos-mt-1 focus:wcpos-ring-indigo-500 focus:wcpos-border-wp-admin-theme-color wcpos-block wcpos-w-full wcpos-shadow-sm sm:wcpos-text-sm wcpos-border-gray-300 wcpos-rounded-md"
					onChange={throttle(handleChange, 100)}
				/>
			</FormRow.Col>
			<FormRow.Col>
				<Button disabled={!key} onClick={() => handleActivation()}>
					Activate
				</Button>
			</FormRow.Col>
		</FormRow>
	);
};

export default License;
