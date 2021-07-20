import * as React from 'react';
import { __ } from '@wordpress/i18n';

const Header = () => {
	return (
		<header className="wcpos-settings-header">
			<h1>{__('Settings', 'wordpress')}</h1>
		</header>
	);
};

export default Header;
