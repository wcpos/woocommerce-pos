import * as React from 'react';
import { __ } from '@wordpress/i18n';
import PosIcon from '../../assets/img/wcpos-icon.svg';

const Header = () => {
	return (
		<header className="wcpos-flex wcpos-items-center wcpos-justify-center wcpos-space-x-4">
			<div className="wcpos-w-16">
				<PosIcon />
			</div>
			<h2 className="wcpos-text-2xl wcpos-font-bold wcpos-leading-7 wcpos-text-gray-900 sm:wcpos-text-3xl sm:wcpos-truncate">
				Settings
			</h2>
		</header>
	);
};

export default Header;
