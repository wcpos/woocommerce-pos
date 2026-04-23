import * as React from 'react';

import { Avatar, Chip } from '@wcpos/ui';

import SessionCard, { Session } from './session-card';
import { Button } from '../../components/ui';
import { t } from '../../translations';

export interface UserSessionData {
	user_id: number;
	username: string;
	display_name: string;
	avatar_url: string;
	session_count: number;
	last_active: number;
	sessions: Session[];
}

interface SessionListProps {
	user: UserSessionData;
	isYou: boolean;
	isActiveNow: boolean;
	isDeleting: boolean;
	onDeleteSession: (jti: string) => void;
	onDeleteAll: (exceptCurrent: boolean) => void;
}

function SessionList({
	user,
	isYou,
	isActiveNow,
	isDeleting,
	onDeleteSession,
	onDeleteAll,
}: SessionListProps) {
	const hasCurrent = user.sessions.some((s) => s.is_current);
	const sortedSessions = React.useMemo(
		() =>
			[...user.sessions].sort((a, b) => {
				if (a.is_current && !b.is_current) return -1;
				if (!a.is_current && b.is_current) return 1;
				return b.last_active - a.last_active;
			}),
		[user.sessions]
	);

	return (
		<div className="wcpos:flex wcpos:h-full wcpos:flex-col">
			<div className="wcpos:mb-3 wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:border-b wcpos:border-gray-200 wcpos:pb-3">
				<Avatar
					name={user.display_name}
					src={user.avatar_url}
					size="lg"
					status={isActiveNow ? 'active' : 'none'}
					statusLabel={t('sessions.active_now')}
				/>
				<div className="wcpos:min-w-0 wcpos:flex-1">
					<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
						<h3 className="wcpos:truncate wcpos:text-base wcpos:font-semibold wcpos:text-gray-900">
							{user.display_name}
						</h3>
						{isYou && (
							<Chip variant="brand" size="xs" shape="pill">
								{t('sessions.you')}
							</Chip>
						)}
						{isActiveNow && (
							<Chip variant="success" size="xs" shape="pill">
								{t('sessions.active_now')}
							</Chip>
						)}
					</div>
					<p className="wcpos:truncate wcpos:text-xs wcpos:text-gray-600">
						@{user.username} &middot;{' '}
						{t('sessions.session_count', { count: user.session_count })}
					</p>
				</div>
				<div className="wcpos:flex wcpos:shrink-0 wcpos:gap-2">
					{hasCurrent && user.sessions.length > 1 && (
						<Button
							variant="outline"
							onClick={() => onDeleteAll(true)}
							disabled={isDeleting}
							className="wcpos:text-xs"
						>
							{t('sessions.logout_other_devices')}
						</Button>
					)}
					{user.sessions.length > 0 && (
						<Button
							variant="destructive"
							onClick={() => onDeleteAll(false)}
							disabled={isDeleting}
							className="wcpos:text-xs"
						>
							{t('sessions.logout_all')}
						</Button>
					)}
				</div>
			</div>

			<div className="wcpos:flex-1 wcpos:space-y-2 wcpos:overflow-y-auto">
				{sortedSessions.map((session) => (
					<SessionCard
						key={session.jti}
						session={session}
						onDelete={() => onDeleteSession(session.jti)}
						isDeleting={isDeleting}
					/>
				))}
			</div>
		</div>
	);
}

export default SessionList;
