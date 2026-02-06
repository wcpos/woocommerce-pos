import * as React from 'react';

import { Button } from '@wordpress/components';

import AllUsersView from './all-users-view';
import MySessionsView from './my-sessions-view';
import Notice from '../../components/notice';
import { t } from '../../translations';

const Sessions = () => {
	const [viewMode, setViewMode] = React.useState<'my' | 'all'>('all');

	return (
		<div className="wcpos:p-4">
			<div className="wcpos:mb-3">
				<Notice status="info" isDismissible={false}>
					{t('sessions.manage_description')}
				</Notice>
			</div>

			{/* View Mode Toggle */}
			<div className="wcpos:mb-3 wcpos:flex wcpos:gap-2">
				<Button
					variant={viewMode === 'all' ? 'primary' : 'secondary'}
					onClick={() => setViewMode('all')}
				>
					{t('sessions.all_users')}
				</Button>
				<Button
					variant={viewMode === 'my' ? 'primary' : 'secondary'}
					onClick={() => setViewMode('my')}
				>
					{t('sessions.my_sessions')}
				</Button>
			</div>

			{/* View content with Suspense */}
			<React.Suspense
				fallback={
					<div className="wcpos:flex wcpos:justify-center wcpos:items-center wcpos:p-8">
						<div className="wcpos:text-center">
							<div className="wcpos:inline-block wcpos:animate-spin wcpos:rounded-full wcpos:h-8 wcpos:w-8 wcpos:border-4 wcpos:border-gray-200 wcpos:border-t-wp-admin-theme-color" />
							<p className="wcpos:mt-2 wcpos:text-sm wcpos:text-gray-600">
								{t('sessions.loading')}
							</p>
						</div>
					</div>
				}
			>
				{viewMode === 'my' ? <MySessionsView /> : <AllUsersView />}
			</React.Suspense>
		</div>
	);
};

export default Sessions;

