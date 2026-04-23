import * as React from 'react';

import SearchIcon from '../../../assets/search.svg';
import UserListItem, { UserSessionData } from './user-list-item';
import { TextInput } from '../../components/ui';
import { t } from '../../translations';

interface UserListProps {
	users: UserSessionData[];
	selectedUserId: number | null;
	currentUserId: number | null;
	activeNowThresholdSeconds: number;
	onSelect: (userId: number) => void;
}

function UserList({
	users,
	selectedUserId,
	currentUserId,
	activeNowThresholdSeconds,
	onSelect,
}: UserListProps) {
	const [filter, setFilter] = React.useState('');

	const nowSeconds = Math.floor(Date.now() / 1000);

	const filtered = React.useMemo(() => {
		const needle = filter.trim().toLowerCase();
		if (!needle) return users;
		return users.filter(
			(u) =>
				u.display_name.toLowerCase().includes(needle) ||
				u.username.toLowerCase().includes(needle)
		);
	}, [users, filter]);

	return (
		<div className="wcpos:flex wcpos:h-full wcpos:flex-col">
			<div className="wcpos:relative wcpos:mb-2">
				<SearchIcon
					className="wcpos:pointer-events-none wcpos:absolute wcpos:left-2 wcpos:top-1/2 wcpos:h-4 wcpos:w-4 wcpos:-translate-y-1/2 wcpos:fill-current wcpos:text-gray-400"
					aria-hidden="true"
					focusable="false"
				/>
				<TextInput
					value={filter}
					onChange={(event) => setFilter(event.target.value)}
					placeholder={t('sessions.filter_users')}
					aria-label={t('sessions.filter_users')}
					className="wcpos:pl-8"
				/>
			</div>
			<div className="wcpos:flex-1 wcpos:overflow-y-auto wcpos:space-y-1 wcpos:pr-1">
				{filtered.length === 0 ? (
					<div className="wcpos:py-6 wcpos:text-center wcpos:text-xs wcpos:text-gray-500">
						{filter.trim()
							? t('sessions.no_users_match')
							: t('sessions.no_other_users')}
					</div>
				) : (
					filtered.map((user) => {
						const isActiveNow = nowSeconds - user.last_active <= activeNowThresholdSeconds;
						return (
							<UserListItem
								key={user.user_id}
								user={user}
								isSelected={user.user_id === selectedUserId}
								isYou={currentUserId !== null && user.user_id === currentUserId}
								isActiveNow={isActiveNow}
								onSelect={() => onSelect(user.user_id)}
							/>
						);
					})
				)}
			</div>
		</div>
	);
}

export default UserList;
