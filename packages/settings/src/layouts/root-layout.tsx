import * as React from 'react';

import { Outlet, useLocation } from '@tanstack/react-router';
import { ErrorBoundary } from 'react-error-boundary';

import { ShellLayout } from '@wcpos/ui';

import { Footer } from './footer';
import { SettingsNavSidebar } from './nav-sidebar';
import { ScreenSkeleton } from './screen-skeleton';
import ErrorFallback from '../components/error';
import Notice from '../components/notice';
import useNotices from '../hooks/use-notices';
import { t } from '../translations';

const pageTitles: Record<string, string> = {
	'/general': 'common.general',
	'/checkout': 'common.checkout',
	'/cloud-print': 'common.cloud_print',
	'/access': 'common.access',
	'/sessions': 'sessions.sessions',
	'/extensions': 'common.extensions',
	'/export-closures': 'export_closures.title',
	'/logs': 'common.logs',
	'/license': 'common.license',
};

export function RootLayout() {
	const { notice, setNotice } = useNotices();
	const [isMobileNavOpen, setIsMobileNavOpen] = React.useState(false);
	const location = useLocation();

	const titleKey = pageTitles[location.pathname] || 'common.settings';
	const pageTitle =
		location.pathname === '/export-closures'
			? t('export_closures.title', 'Export closures')
			: t(titleKey);

	return (
		<ShellLayout
			sidebar={
				<SettingsNavSidebar
					isOpen={isMobileNavOpen}
					onNavItemClick={() => setIsMobileNavOpen(false)}
				/>
			}
			title={
				<h1 className="wcpos:text-lg wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0">
					{pageTitle}
				</h1>
			}
			mobileNavOpen={isMobileNavOpen}
			onToggleMobileNav={() => setIsMobileNavOpen((open) => !open)}
			footer={<Footer />}
		>
			{notice && (
				<div className="wcpos:mb-4">
					<Notice status={notice.type} onRemove={() => setNotice(null)}>
						{notice.message}
					</Notice>
				</div>
			)}

			<ErrorBoundary FallbackComponent={ErrorFallback}>
				<React.Suspense fallback={<ScreenSkeleton />}>
					<Outlet />
				</React.Suspense>
			</ErrorBoundary>
		</ShellLayout>
	);
}
