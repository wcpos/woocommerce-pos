import * as React from 'react';

import Book from '../../assets/book.svg';
import Question from '../../assets/comment-question.svg';
import Discord from '../../assets/discord.svg';
import Email from '../../assets/email.svg';
import { t } from '../translations';

const Footer = () => {
	return (
		<div className="wcpos-px-4 wcpos-py-5 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4">
			<div>
				<h3 className="wcpos-mt-0">{t('Need help?', { _tags: 'wp-admin-settings' })}</h3>
			</div>
			<div className="wcpos-mt-1 sm:wcpos-mt-0 wcpos-space-y-2">
				<p className="wcpos-flex wcpos-items-center wcpos-mt-0">
					<span className="wcpos-mr-2 wcpos-h-4 wcpos-w-4">
						<Book fill="#3c434a" />
					</span>
					<a href="https://docs.wcpos.com" target="_blank" rel="noreferrer">
						{t('Documentation', { _tags: 'wp-admin-settings' })}
					</a>
				</p>
				<p className="wcpos-flex wcpos-items-center">
					<span className="wcpos-mr-2 wcpos-h-4 wcpos-w-4">
						<Question fill="#3c434a" />
					</span>
					<a href="https://faq.wcpos.com" target="_blank" rel="noreferrer">
						{t('Frequently Asked Questions', { _tags: 'wp-admin-settings' })}
					</a>
				</p>
				<p className="wcpos-flex wcpos-items-center">
					<span className="wcpos-mr-2 wcpos-h-4 wcpos-w-4">
						<Email fill="#3c434a" />
					</span>
					<a href="mailto:support@wcpos.com" target="_blank" rel="noreferrer">
						support@wcpos.com
					</a>
				</p>
				<p className="wcpos-flex wcpos-items-center">
					<span className="wcpos-mr-2 wcpos-h-4 wcpos-w-4">
						<Discord fill="#3c434a" />
					</span>
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
