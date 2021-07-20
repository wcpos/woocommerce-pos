import * as React from 'react';
import { __ } from '@wordpress/i18n';

const Footer = () => {
	return <footer className="wcpos-footer">{__('Test', 'woocommerce-pos')}</footer>;
};

export default Footer;
