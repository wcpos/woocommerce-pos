import * as React from 'react';

import PosIcon from '../../assets/wcpos-icon.svg';
import { t } from '../translations';

const Header = () => {
	return (
		<header className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:space-x-4">
			<div className="wcpos:w-16">
				<PosIcon />
			</div>
			<h2 className="wcpos:text-2xl wcpos:font-bold wcpos:text-gray-900 wcpos:sm:text-3xl wcpos:sm:truncate">
				{t('Settings', { _tags: 'wp-admin-settings' })}
			</h2>
		</header>
	);
};

export default Header;
