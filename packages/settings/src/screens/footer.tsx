import * as React from 'react';

import { Icon } from '@wordpress/components';

import { t } from '../translations';

const Footer = () => {
	return (
		<div className="wcpos-px-4 wcpos-py-5 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4">
			<div>
				<h3 className="wcpos-mt-0">{t('Need help?', { _tags: 'wp-admin-settings' })}</h3>
			</div>
			<div className="wcpos-mt-1 sm:wcpos-mt-0 wcpos-space-y-2">
				<p className="wcpos-flex wcpos-items-center wcpos-mt-0">
					<Icon icon="book" className="wcpos-mr-2 wcpos-text-gray-500" />
					<a href="https://docs.wcpos.com" target="_blank" rel="noreferrer">
						{t('Documentation', { _tags: 'wp-admin-settings' })}
					</a>
				</p>
				<p className="wcpos-flex wcpos-items-center">
					<Icon icon="feedback" className="wcpos-mr-2 wcpos-text-gray-500" />
					<a href="https://faq.wcpos.com" target="_blank" rel="noreferrer">
						{t('Frequently Asked Questions', { _tags: 'wp-admin-settings' })}
					</a>
				</p>
				<p className="wcpos-flex wcpos-items-center">
					<Icon icon="email" className="wcpos-mr-2 wcpos-text-gray-500" />
					<a href="mailto:support@wcpos.com" target="_blank" rel="noreferrer">
						support@wcpos.com
					</a>
				</p>
				<p className="wcpos-flex wcpos-items-center">
					<Icon icon="testimonial" className="wcpos-mr-2 wcpos-text-gray-500" />
					<a href="https://wcpos.com/discord" target="_blank" rel="noreferrer">
						<img
							id="discord-badge"
							src="https://img.shields.io/discord/711884517081612298?color=%232271B1&amp;logoColor=white"
							alt="Discord Chat"
						/>
					</a>
				</p>
			</div>
		</div>
	);
};

export default Footer;
