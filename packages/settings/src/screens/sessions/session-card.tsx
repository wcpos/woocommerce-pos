import * as React from 'react';

import { Button } from '@wordpress/components';
import classNames from 'classnames';

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

interface SessionCardProps {
	session: Session;
	onDelete: () => void;
	isDeleting: boolean;
}

const SessionCard: React.FC<SessionCardProps> = ({ session, onDelete, isDeleting }) => {
	const formatTimestamp = (timestamp: number) => {
		try {
			const date = new Date(timestamp * 1000);
			return date.toLocaleString(undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			});
		} catch {
			return 'N/A';
		}
	};

	const getDeviceIcon = (deviceInfo: Session['device_info']) => {
		// Check app type first
		switch (deviceInfo.app_type) {
			case 'ios_app':
				// Default to tablet for iOS unless explicitly iPhone
				return deviceInfo.device_type === 'mobile' ? '📱' : '📲';
			case 'android_app':
				// Default to tablet for Android unless explicitly mobile
				return deviceInfo.device_type === 'mobile' ? '📱' : '📲';
			case 'electron_app':
				return '💻'; // Desktop app icon
			case 'web':
			default:
				// Web browser - use globe for desktop, phone/tablet for mobile
				switch (deviceInfo.device_type) {
					case 'mobile':
						return '📱';
					case 'tablet':
						return '📲';
					case 'desktop':
					default:
						return '🌐'; // Globe icon for web
				}
		}
	};

	const getAppTypeLabel = (appType?: string) => {
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
	};

	const getTimeAgo = (timestamp: number) => {
		const now = Math.floor(Date.now() / 1000);
		const diff = now - timestamp;

		if (diff < 60) return t('sessions.just_now');
		if (diff < 3600) return t('sessions.minutes_ago', { minutes: Math.floor(diff / 60) });
		if (diff < 86400) return t('sessions.hours_ago', { hours: Math.floor(diff / 3600) });
		return t('sessions.days_ago', { days: Math.floor(diff / 86400) });
	};

	return (
		<div
			className={classNames(
				'wcpos:border wcpos:rounded-md wcpos:overflow-hidden wcpos:transition-all',
				session.is_current
					? 'wcpos:border-wp-admin-theme-color wcpos:ring-1 wcpos:ring-wp-admin-theme-color wcpos:ring-opacity-30'
					: 'wcpos:border-gray-200 wcpos:bg-white hover:wcpos:border-gray-300'
			)}
		>
			{/* Header with gradient for current session */}
			{session.is_current && (
				<div className="wcpos:bg-linear-to-r wcpos:from-wp-admin-theme-color wcpos:to-wp-admin-theme-color-darker wcpos:px-3 wcpos:py-1">
					<span className="wcpos:text-xs wcpos:font-medium wcpos:text-white wcpos:flex wcpos:items-center wcpos:gap-1">
						<span className="wcpos:text-[10px]">●</span> {t('sessions.current_session')}
					</span>
				</div>
			)}

			<div className="wcpos:p-3">
				<div className="wcpos:flex wcpos:items-start wcpos:gap-3">
					{/* Icon */}
					<div
						className={classNames(
							'wcpos:shrink-0 wcpos:w-10 wcpos:h-10 wcpos:rounded-md wcpos:flex wcpos:items-center wcpos:justify-center wcpos:text-xl',
							session.is_current
								? 'wcpos:bg-wp-admin-theme-color-lightest'
								: 'wcpos:bg-gray-100'
						)}
					>
						{getDeviceIcon(session.device_info)}
					</div>

					{/* Content */}
					<div className="wcpos:flex-1 wcpos:min-w-0">
						{/* Title and button */}
						<div className="wcpos:flex wcpos:items-start wcpos:justify-between wcpos:gap-2">
							<div className="wcpos:flex-1">
								<h3 className="wcpos:font-semibold wcpos:text-sm wcpos:text-gray-900 wcpos:leading-tight">
									{getAppTypeLabel(session.device_info.app_type)}
									{session.device_info.browser_version && (
										<span className="wcpos:text-gray-500 wcpos:font-normal wcpos:text-xs">
											{' '}
											{session.device_info.browser_version}
										</span>
									)}
								</h3>
								<p className="wcpos:text-xs wcpos:text-gray-600 wcpos:mt-0.5">
									{session.device_info.browser} • {session.device_info.os}
								</p>
							</div>

							{/* Terminate button */}
							{!session.is_current && (
								<Button
									variant="secondary"
									isDestructive
									size="small"
									onClick={onDelete}
									disabled={isDeleting}
									className="wcpos:shrink-0"
								>
									{t('sessions.terminate')}
								</Button>
							)}
						</div>

						{/* Last active */}
						<div className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:mt-2 wcpos:mb-2">
							<span className="wcpos:text-xs wcpos:text-gray-600">
								{getTimeAgo(session.last_active)}
							</span>
						</div>

						{/* Compact details section */}
						<div className="wcpos:bg-gray-50 wcpos:rounded wcpos:px-2 wcpos:py-1.5 wcpos:space-y-1">
							<div className="wcpos:flex wcpos:justify-between wcpos:text-xs wcpos:items-center">
								<span className="wcpos:text-gray-500">{t('sessions.ip')}</span>
								<span className="wcpos:text-gray-900 wcpos:text-[11px]">{session.ip_address || 'N/A'}</span>
							</div>
							<div className="wcpos:flex wcpos:justify-between wcpos:text-xs wcpos:items-center wcpos:border-t wcpos:border-gray-200 wcpos:pt-1">
								<span className="wcpos:text-gray-500">{t('common.created')}</span>
								<span className="wcpos:text-gray-900 wcpos:text-[11px]">{formatTimestamp(session.created)}</span>
							</div>
							<div className="wcpos:flex wcpos:justify-between wcpos:text-xs wcpos:items-center wcpos:border-t wcpos:border-gray-200 wcpos:pt-1">
								<span className="wcpos:text-gray-500">{t('common.expires')}</span>
								<span className="wcpos:text-gray-900 wcpos:text-[11px]">{formatTimestamp(session.expires)}</span>
							</div>
						</div>

						{/* User Agent - compact version */}
						{session.user_agent && (
							<details className="wcpos:mt-2 wcpos:group">
								<summary className="wcpos:text-[11px] wcpos:text-gray-500 wcpos:cursor-pointer hover:wcpos:text-gray-700 wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:select-none">
									<span className="wcpos:transition-transform group-open:wcpos:rotate-90 wcpos:text-[8px]">▶</span>
									{t('sessions.user_agent')}
								</summary>
								<div className="wcpos:mt-1 wcpos:p-1.5 wcpos:bg-gray-50 wcpos:rounded wcpos:border wcpos:border-gray-200">
									<p className="wcpos:text-[10px] wcpos:text-gray-700 wcpos:font-mono wcpos:break-all wcpos:leading-tight">
										{session.user_agent}
									</p>
								</div>
							</details>
						)}
					</div>
				</div>
			</div>
		</div>
	);
};

export default SessionCard;

