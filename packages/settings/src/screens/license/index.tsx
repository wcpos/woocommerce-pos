import * as React from 'react';

import { Button } from '@wordpress/components';
import { addQueryArgs } from '@wordpress/url';
import { get, throttle, isString } from 'lodash';

import Label from '../../components/label';
import useNotices from '../../hooks/use-notices';
import useSettingsApi from '../../hooks/use-settings-api';
import { t, T } from '../../translations';

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
	const [key, setKey] = React.useState(data?.key);

	const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => setKey(event.target.value);

	const handleActivation = async (deactivate = false) => {
		const url = addQueryArgs('https://wcpos.com', {
			'wc-api': 'am-software-api',
			request: deactivate ? 'deactivation' : 'activation',
			instance: data?.instance,
			api_key: key,
			product_id: data?.product_id,
			platform: data?.platform,
			version: data?.version,
			timestamp: Date.now(),
		});

		const response = await fetch(url, {
			method: 'GET',
			credentials: 'omit',
		})
			.then((res) => res.json())
			.catch((err) => {
				setNotice({ type: 'error', message: err.message });
			});

		if (!response.success) {
			setNotice({ type: 'error', message: response.error });
		} else {
			if (deactivate) {
				setKey('');
			} else {
				const confetti = get(window, 'confetti') as unknown as () => void;
				if (confetti) {
					confetti();
				}
			}

			mutate({
				key: deactivate ? '' : key,
				activated: !!response.activated,
			});
		}
	};

	if (!data?.instance) {
		return (
			<div className="wcpos-px-4 wcpos-py-5 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4 sm:wcpos-px-6 wcpos-items-center">
				<div className="sm:wcpos-text-right wcpos-text-8xl">
					<a href="https://wcpos.com/pro">
						<img
							src="https://wcpos.com/wp-content/themes/woocommerce-pos/img/woopos-pro-logo-600.png"
							alt-="WooCommerce POS Pro"
							width={100}
							height={100}
						/>
					</a>
				</div>
				<div className="wcpos-col-span-2">
					<p>
						{t(
							'If you would like to support the development of WooCommerce POS, please consider purchasing a Pro license.',
							{
								_tags: 'wp-admin-settings',
							}
						)}
					</p>
					<p>
						<a href="https://wcpos.com/pro">
							{t('Upgrade to WooCommerce POS Pro', {
								_tags: 'wp-admin-settings',
							})}
						</a>
					</p>
				</div>
			</div>
		);
	}

	if (data.activated) {
		return (
			<div className="wcpos-px-4 wcpos-py-5 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4 sm:wcpos-px-6 wcpos-items-center">
				<div className="sm:wcpos-text-right wcpos-text-8xl">🎉</div>
				<div className="wcpos-col-span-2">
					<h3>{t('Thank You!', { _tags: 'wp-admin-settings' })}</h3>
					<p>
						<T
							_str="License {number} has been activated."
							_tags="wc-admin-settings"
							number={<code>{truncate(isString(data?.key) ? data?.key : '')}</code>}
						/>
					</p>
					<p>
						{t('Your support helps fund the ongoing development of WooCommerce POS.', {
							_tags: 'wp-admin-settings',
						})}
					</p>
					<Button variant="primary" onClick={() => handleActivation(true)}>
						Deactivate
					</Button>
				</div>
			</div>
		);
	}

	return (
		<div className="wcpos-px-4 wcpos-py-5 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4">
			<div className="wcpos-flex sm:wcpos-justify-end">
				<Label>{t('License Key', { _tags: 'wp-admin-settings' })}</Label>
			</div>
			<div>
				<input
					type="text"
					name="license-key"
					id="license-key"
					className="wcpos-mt-1 focus:wcpos-ring-indigo-500 focus:wcpos-border-wp-admin-theme-color wcpos-block wcpos-w-full wcpos-shadow-sm sm:wcpos-text-sm wcpos-border-gray-300 wcpos-rounded-md"
					onChange={throttle(handleChange, 100)}
				/>
			</div>
			<div>
				<Button variant="primary" disabled={!key} onClick={() => handleActivation()}>
					{t('Activate', { _tags: 'wp-admin-settings' })}
				</Button>
			</div>
		</div>
	);
};

export default License;
