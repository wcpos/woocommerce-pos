import * as React from 'react';

import { addQueryArgs } from '@wordpress/url';
import { get, throttle, isString } from 'lodash';

import Label from '../../components/label';
import Notice from '../../components/notice';
import { Button } from '../../components/ui';
import useNotices from '../../hooks/use-notices';
import useSettingsApi from '../../hooks/use-settings-api';
import { t, Trans } from '../../translations';

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

function License() {
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
			<div className="wcpos:flex wcpos:flex-col wcpos:items-center wcpos:gap-6 wcpos:px-4 wcpos:py-8 wcpos:max-w-md wcpos:mx-auto">
				<a href="https://wcpos.com/pro">
					<img
						src="https://wcpos.com/wp-content/uploads/2025/07/wcpos-pro-icon.png"
						alt="WCPOS Pro"
						width={80}
						height={80}
					/>
				</a>
				<div className="wcpos:text-center">
					<p className="wcpos:text-gray-600 wcpos:mb-3">{t('license.support_development')}</p>
					<a
						href="https://wcpos.com/pro"
						className="wcpos:inline-block wcpos:font-medium wcpos:text-[color:var(--wp-admin-theme-color)] hover:wcpos:underline"
					>
						{t('common.upgrade_to_pro')} &rarr;
					</a>
				</div>
				<Notice status="info" isDismissible={false} className="wcpos:w-full">
					{t('license.existing_license_prefix')}{' '}
					<a
						href="https://docs.wcpos.com/getting-started/pro-license#installation"
						target="_blank"
						rel="noopener noreferrer"
						className="wcpos:font-medium wcpos:underline"
					>
						{t('license.existing_license_link')}
					</a>{' '}
					{t('license.existing_license_suffix')}
				</Notice>
			</div>
		);
	}

	if (data.activated) {
		return (
			<div className="wcpos:px-4 wcpos:py-5 wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4 wcpos:sm:px-6 wcpos:items-center">
				<div className="wcpos:sm:text-right wcpos:text-8xl">🎉</div>
				<div className="wcpos:col-span-2">
					<h3>{t('license.thank_you')}</h3>
					<p>
						<Trans
							i18nKey="license.activated"
							components={{ code: <code /> }}
							values={{ number: truncate(isString(data?.key) ? data?.key : '') }}
						/>
					</p>
					<p>{t('license.ongoing_support')}</p>
					<Button variant="primary" onClick={() => handleActivation(true)}>
						{t('license.deactivate')}
					</Button>
				</div>
			</div>
		);
	}

	return (
		<div className="wcpos:px-4 wcpos:py-5 wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4">
			<div className="wcpos:flex wcpos:sm:justify-end">
				<Label>{t('license.license_key')}</Label>
			</div>
			<div>
				<input
					type="text"
					name="license-key"
					id="license-key"
					className="wcpos:mt-1 wcpos:focus:ring-indigo-500 wcpos:focus:border-wp-admin-theme-color wcpos:block wcpos:w-full wcpos:shadow-xs wcpos:sm:text-sm wcpos:border-gray-300 wcpos:rounded-md"
					onChange={throttle(handleChange, 100)}
				/>
			</div>
			<div>
				<Button variant="primary" disabled={!key} onClick={() => handleActivation()}>
					{t('license.activate')}
				</Button>
			</div>
		</div>
	);
}

export default License;
