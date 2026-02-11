import * as React from 'react';

import { Outlet, useLocation } from '@tanstack/react-router';
import { ErrorBoundary } from 'react-error-boundary';

import { Footer } from './footer';
import { NavSidebar } from './nav-sidebar';
import ErrorFallback from '../components/error';
import Notice from '../components/notice';
import useNotices from '../hooks/use-notices';
import { t } from '../translations';

const pageTitles: Record<string, string> = {
	'/general': 'common.general',
	'/checkout': 'common.checkout',
	'/access': 'common.access',
	'/sessions': 'sessions.sessions',
	'/logs': 'common.logs',
	'/license': 'common.license',
};

export function RootLayout() {
	const { notice, setNotice } = useNotices();
	const [isMobileNavOpen, setIsMobileNavOpen] = React.useState(false);
	const location = useLocation();

	const titleKey = pageTitles[location.pathname] || 'common.settings';
	const pageTitle = t(titleKey);

	return (
		<div className="wcpos:flex-1 wcpos:flex wcpos:bg-white">
			<NavSidebar isOpen={isMobileNavOpen} onNavItemClick={() => setIsMobileNavOpen(false)} />

			<div className="wcpos:flex-1 wcpos:flex wcpos:flex-col wcpos:min-w-0">
				{/* Title bar */}
				<div className="wcpos:flex wcpos:items-center wcpos:px-6 wcpos:border-b wcpos:border-gray-200 wcpos:shrink-0 wcpos:h-12">
					<button
						type="button"
						aria-label="Open main menu"
						aria-expanded={isMobileNavOpen}
						onClick={() => setIsMobileNavOpen((open) => !open)}
						className="wcpos:lg:hidden wcpos:p-2 wcpos:mr-2 wcpos:rounded-md wcpos:text-gray-600 hover:wcpos:bg-gray-100"
					>
						<svg
							className="wcpos:h-5 wcpos:w-5"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								strokeWidth={2}
								d="M4 6h16M4 12h16M4 18h16"
							/>
						</svg>
					</button>
					<h1 className="wcpos:text-lg wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0">
						{pageTitle}
					</h1>
				</div>

				<main className="wcpos:flex-1 wcpos:px-6 wcpos:py-6">
					{notice && (
						<div className="wcpos:mb-4">
							<Notice status={notice.type} onRemove={() => setNotice(null)}>
								{notice.message}
							</Notice>
						</div>
					)}

					<ErrorBoundary FallbackComponent={ErrorFallback}>
						<React.Suspense fallback={null}>
							<Outlet />
						</React.Suspense>
					</ErrorBoundary>
				</main>

				<Footer />
			</div>
		</div>
	);
}
