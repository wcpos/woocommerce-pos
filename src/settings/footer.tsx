import * as React from 'react';
import { __ } from '@wordpress/i18n';

const Footer = () => {
	return (
		<footer id="woocommerce-pos-settings-footer">
			{__('Need help? ', 'woocommerce-pos')}
			<a href="https://docs.wcpos.com" target="_blank" rel="noreferrer">
				{__('Docs')}
			</a>
			<span className="text-separator">|</span>
			<a href="https://faq.wcpos.com" target="_blank" rel="noreferrer">
				{__('F.A.Q.')}
			</a>
			<span className="text-separator">|</span>
			<a href="https://wcpos.com/discord" target="_blank" rel="noreferrer">
				<img
					id="discord-badge"
					src="https://img.shields.io/discord/711884517081612298?color=%232271B1&amp;logoColor=white"
					alt="Discord Chat"
				/>
			</a>
		</footer>
	);
};

export default Footer;
