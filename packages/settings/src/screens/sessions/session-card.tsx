import * as React from 'react';

import classNames from 'classnames';

import { Chip } from '@wcpos/ui';

import DeviceIcon from './device-icon';
import { Button } from '../../components/ui';
import { t } from '../../translations';

export interface Session {
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

function getAppTypeLabel(appType?: string) {
	switch (appType) {
		case 'ios_app':
			return t('sessions.ios_application');
		case 'android_app':
			return t('sessions.android_application');
		case 'electron_app':
			return t('sessions.desktop_application');
		case 'web':
		default:
			return t('sessions.web_application');
	}
}

function formatTimestamp(timestamp: number) {
	try {
		return new Date(timestamp * 1000).toLocaleString(undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		});
	} catch {
		return 'N/A';
	}
}

function getTimeAgo(timestamp: number) {
	const now = Math.floor(Date.now() / 1000);
	const diff = now - timestamp;
	if (diff < 60) return t('sessions.just_now');
	if (diff < 3600) return t('sessions.minutes_ago', { minutes: Math.floor(diff / 60) });
	if (diff < 86400) return t('sessions.hours_ago', { hours: Math.floor(diff / 3600) });
	return t('sessions.days_ago', { days: Math.floor(diff / 86400) });
}

function SessionCard({
	session,
	onDelete,
	isDeleting,
}: {
	session: Session;
	onDelete: () => void;
	isDeleting: boolean;
}) {
	return (
		<div
			className={classNames(
				'wcpos:rounded-md wcpos:border wcpos:bg-white wcpos:p-3 wcpos:transition-colors',
				session.is_current
					? 'wcpos:border-wp-admin-theme-color wcpos:ring-1 wcpos:ring-wp-admin-theme-color/30'
					: 'wcpos:border-gray-200 hover:wcpos:border-gray-300'
			)}
		>
			<div className="wcpos:flex wcpos:items-start wcpos:gap-3">
				<div
					className={classNames(
						'wcpos:shrink-0 wcpos:flex wcpos:h-10 wcpos:w-10 wcpos:items-center wcpos:justify-center wcpos:rounded-md',
						session.is_current
							? 'wcpos:bg-wp-admin-theme-color-lightest wcpos:text-wp-admin-theme-color'
							: 'wcpos:bg-gray-100 wcpos:text-gray-500'
					)}
				>
					<DeviceIcon deviceInfo={session.device_info} className="wcpos:h-5 wcpos:w-5" />
				</div>

				<div className="wcpos:min-w-0 wcpos:flex-1">
					<div className="wcpos:flex wcpos:items-start wcpos:justify-between wcpos:gap-2">
						<div className="wcpos:min-w-0 wcpos:flex-1">
							<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
								<h4 className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900 wcpos:leading-tight wcpos:truncate">
									{getAppTypeLabel(session.device_info.app_type)}
									{session.device_info.browser_version && (
										<span className="wcpos:ml-1 wcpos:text-xs wcpos:font-normal wcpos:text-gray-500">
											{session.device_info.browser_version}
										</span>
									)}
								</h4>
								{session.is_current && (
									<Chip variant="brand" size="xs" shape="pill">
										{t('sessions.current_device')}
									</Chip>
								)}
							</div>
							<p className="wcpos:mt-0.5 wcpos:text-xs wcpos:text-gray-600 wcpos:truncate">
								{session.device_info.browser} &middot; {session.device_info.os}
							</p>
						</div>

						<Button
							variant="ghost-destructive"
							onClick={onDelete}
							disabled={isDeleting}
							className="wcpos:shrink-0 wcpos:px-2 wcpos:py-1 wcpos:text-xs"
						>
							{t('sessions.terminate')}
						</Button>
					</div>

					<div className="wcpos:mt-2 wcpos:grid wcpos:grid-cols-2 wcpos:gap-x-4 wcpos:gap-y-1 wcpos:rounded wcpos:bg-gray-50 wcpos:px-2 wcpos:py-1.5 wcpos:text-xs">
						<div className="wcpos:flex wcpos:justify-between wcpos:gap-2">
							<span className="wcpos:text-gray-500">{t('sessions.last_active')}</span>
							<span className="wcpos:text-gray-900">{getTimeAgo(session.last_active)}</span>
						</div>
						<div className="wcpos:flex wcpos:justify-between wcpos:gap-2">
							<span className="wcpos:text-gray-500">{t('sessions.ip')}</span>
							<span className="wcpos:text-gray-900">{session.ip_address || 'N/A'}</span>
						</div>
						<div className="wcpos:flex wcpos:justify-between wcpos:gap-2">
							<span className="wcpos:text-gray-500">{t('sessions.created')}</span>
							<span className="wcpos:text-gray-900">{formatTimestamp(session.created)}</span>
						</div>
						<div className="wcpos:flex wcpos:justify-between wcpos:gap-2">
							<span className="wcpos:text-gray-500">{t('sessions.expires')}</span>
							<span className="wcpos:text-gray-900">{formatTimestamp(session.expires)}</span>
						</div>
					</div>

					{session.user_agent && (
						<details className="wcpos:mt-2 wcpos:group">
							<summary className="wcpos:flex wcpos:cursor-pointer wcpos:select-none wcpos:items-center wcpos:gap-1 wcpos:text-[11px] wcpos:text-gray-500 hover:wcpos:text-gray-700">
								<span className="wcpos:text-[8px] wcpos:transition-transform group-open:wcpos:rotate-90">
									&#9654;
								</span>
								{t('sessions.user_agent')}
							</summary>
							<div className="wcpos:mt-1 wcpos:rounded wcpos:border wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:p-1.5">
								<p className="wcpos:break-all wcpos:font-mono wcpos:text-[10px] wcpos:leading-tight wcpos:text-gray-700">
									{session.user_agent}
								</p>
							</div>
						</details>
					)}
				</div>
			</div>
		</div>
	);
}

export default SessionCard;
