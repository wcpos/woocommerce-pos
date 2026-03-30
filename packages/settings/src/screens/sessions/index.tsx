import * as React from 'react';

import AllUsersView from './all-users-view';
import MySessionsView from './my-sessions-view';
import Notice from '../../components/notice';
import { ListSkeleton } from '../../components/skeleton';
import { Button } from '../../components/ui';
import { t } from '../../translations';

function Sessions() {
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
			<React.Suspense fallback={<ListSkeleton rows={4} />}>
				{viewMode === 'my' ? <MySessionsView /> : <AllUsersView />}
			</React.Suspense>
		</div>
	);
}

export default Sessions;
