import * as React from 'react';

import classNames from 'classnames';

import { Avatar, Chip } from '@wcpos/ui';

import { t } from '../../translations';

export interface UserSessionData {
	user_id: number;
	username: string;
	display_name: string;
	avatar_url: string;
	session_count: number;
	last_active: number;
	sessions: unknown[];
}

interface UserListItemProps {
	user: UserSessionData;
	isSelected: boolean;
	isYou: boolean;
	isActiveNow: boolean;
	onSelect: () => void;
}

function UserListItem({
	user,
	isSelected,
	isYou,
	isActiveNow,
	onSelect,
}: UserListItemProps) {
	return (
		<button
			type="button"
			onClick={onSelect}
			aria-pressed={isSelected}
			className={classNames(
				'wcpos:flex wcpos:w-full wcpos:items-center wcpos:gap-3 wcpos:rounded-md wcpos:border wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:transition-colors',
				isSelected
					? 'wcpos:border-wp-admin-theme-color wcpos:bg-wp-admin-theme-color-lightest'
					: 'wcpos:border-transparent wcpos:bg-white hover:wcpos:border-gray-200 hover:wcpos:bg-gray-50'
			)}
		>
			<Avatar
				name={user.display_name}
				src={user.avatar_url}
				size="md"
				status={isActiveNow ? 'active' : 'none'}
				statusLabel={t('sessions.active_now')}
			/>
			<div className="wcpos:min-w-0 wcpos:flex-1">
				<div className="wcpos:flex wcpos:items-center wcpos:gap-1.5">
					<span className="wcpos:truncate wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
						{user.display_name}
					</span>
					{isYou && (
						<Chip variant="brand" size="xs" shape="pill">
							{t('sessions.you')}
						</Chip>
					)}
				</div>
				<div className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:text-xs wcpos:text-gray-600">
					<span className="wcpos:truncate">@{user.username}</span>
				</div>
			</div>
			<Chip variant="neutral" size="xs" shape="round">
				{user.session_count}
			</Chip>
		</button>
	);
}

export default UserListItem;
