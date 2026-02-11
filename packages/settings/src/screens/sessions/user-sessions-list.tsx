import * as React from 'react';

import classNames from 'classnames';

import SessionCard from './session-card';
import { Button } from '../../components/ui';
import { t } from '../../translations';

interface Session {
	jti: string;
	created: number;
	last_active: number;
	expires: number;
	ip_address: string;
	user_agent: string;
	device_info: {
		device_type: string;
		browser: string;
		browser_version: string;
		os: string;
		app_type?: string; // web, ios_app, android_app, electron_app
	};
	is_current?: boolean;
}

interface UserSessionData {
	user_id: number;
	username: string;
	display_name: string;
	avatar_url: string;
	session_count: number;
	last_active: number;
	sessions: Session[];
}

function UserSessionsList({
	users,
	onDeleteSession,
	onDeleteAllSessions,
	isDeleting,
}: {
	users: UserSessionData[];
	onDeleteSession: (userId: number, jti: string) => void;
	onDeleteAllSessions: (userId: number, exceptCurrent?: boolean) => void;
	isDeleting: boolean;
}) {
	const [expandedUsers, setExpandedUsers] = React.useState<Set<number>>(new Set());

	const toggleUser = (userId: number) => {
		setExpandedUsers((prev) => {
			const next = new Set(prev);
			if (next.has(userId)) {
				next.delete(userId);
			} else {
				next.add(userId);
			}
			return next;
		});
	};

	const getTimeAgo = (timestamp: number) => {
		const now = Math.floor(Date.now() / 1000);
		const diff = now - timestamp;

		if (diff < 60) return t('sessions.just_now');
		if (diff < 3600) return t('sessions.minutes_ago', { minutes: Math.floor(diff / 60) });
		if (diff < 86400) return t('sessions.hours_ago', { hours: Math.floor(diff / 3600) });
		return t('sessions.days_ago', { days: Math.floor(diff / 86400) });
	};

	if (!users || users.length === 0) {
		return (
			<div className="wcpos:text-center wcpos:py-8 wcpos:text-gray-500">
				{t('sessions.no_active_sessions')}
			</div>
		);
	}

	return (
		<div className="wcpos:space-y-2">
			<h2 className="wcpos:text-base wcpos:font-medium wcpos:mb-3">
				{t('sessions.active_users')} ({users.length})
			</h2>

			{users.map((user) => {
				const isExpanded = expandedUsers.has(user.user_id);

				return (
					<div
						key={user.user_id}
						className="wcpos:border wcpos:border-gray-200 wcpos:rounded-md wcpos:overflow-hidden"
					>
						{/* User Header - more compact */}
						<div
							className={classNames(
								'wcpos:px-3 wcpos:py-2.5 wcpos:cursor-pointer wcpos:transition-colors',
								isExpanded ? 'wcpos:bg-gray-50' : 'wcpos:bg-white hover:wcpos:bg-gray-50'
							)}
							onClick={() => toggleUser(user.user_id)}
						>
							<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:gap-3">
								<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:flex-1 wcpos:min-w-0">
									<img
										src={user.avatar_url}
										alt={user.display_name}
										className="wcpos:shrink-0 wcpos:w-10 wcpos:h-10 wcpos:rounded-full wcpos:border-2 wcpos:border-gray-200"
									/>
									<div className="wcpos:flex-1 wcpos:min-w-0">
										<h3 className="wcpos:font-medium wcpos:text-sm wcpos:text-gray-900 wcpos:truncate">
											{user.display_name}
										</h3>
										<div className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:text-xs wcpos:text-gray-600">
											<span className="wcpos:truncate">@{user.username}</span>
											<span className="wcpos:text-gray-400">•</span>
											<span className="wcpos:shrink-0">
												{t('sessions.session_count', { count: user.session_count })}
											</span>
											<span className="wcpos:text-gray-400">•</span>
											<span className="wcpos:shrink-0">{getTimeAgo(user.last_active)}</span>
										</div>
									</div>
								</div>

								<div className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:shrink-0">
									{user.session_count > 0 && (
										<Button
											variant="destructive"
											onClick={(e: React.MouseEvent) => {
												e.stopPropagation();
												onDeleteAllSessions(user.user_id);
											}}
											disabled={isDeleting}
											className="wcpos:text-xs wcpos:px-2 wcpos:py-1"
										>
											{t('sessions.logout_all')}
										</Button>
									)}
									<span
										className={classNames(
											'wcpos:transition-transform wcpos:duration-200 wcpos:text-gray-400 wcpos:text-sm',
											isExpanded && 'wcpos:rotate-180'
										)}
									>
										▼
									</span>
								</div>
							</div>
						</div>

						{/* User Sessions (Expanded) */}
						{isExpanded && (
							<div className="wcpos:px-3 wcpos:pb-3 wcpos:space-y-2 wcpos:bg-gray-50">
								{user.sessions.map((session) => (
									<SessionCard
										key={session.jti}
										session={session}
										onDelete={() => onDeleteSession(user.user_id, session.jti)}
										isDeleting={isDeleting}
									/>
								))}
							</div>
						)}
					</div>
				);
			})}
		</div>
	);
}

export default UserSessionsList;
