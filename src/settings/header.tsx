import * as React from 'react';
import { __ } from '@wordpress/i18n';
import PosIcon from '../../assets/img/wcpos-icon.svg';

const Header = () => {
	return (
		<header className="flex justify-between items-center">
			<PosIcon />
			<h1>{__('Settings', 'wordpress')}</h1>
		</header>
	);
};

export default Header;
