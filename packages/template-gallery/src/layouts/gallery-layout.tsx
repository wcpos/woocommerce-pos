import * as React from 'react';
import { Outlet } from '@tanstack/react-router';

import { TypeTabs } from '../components/type-tabs';

export function GalleryLayout() {
	const [activeType, setActiveType] = React.useState('receipt');

	return (
		<div className="wcpos:max-w-7xl">
			<div className="wcpos:mb-6">
				<h1 className="wcpos:text-2xl wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0">
					Receipt Templates
				</h1>
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mt-1">
					Customise your POS receipts.
				</p>
			</div>

			<TypeTabs activeType={activeType} onChange={setActiveType} />

			<Outlet />
		</div>
	);
}
