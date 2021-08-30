import * as React from 'react';
import { __ } from '@wordpress/i18n';
import PosIcon from '../../assets/img/wcpos-icon.svg';

const Header = () => {
	return (
		<header className="flex items-center justify-center space-x-4">
			<div className="w-16">
				<PosIcon />
			</div>
			<h2 className="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
				Settings
			</h2>
		</header>
	);
};

export default Header;
