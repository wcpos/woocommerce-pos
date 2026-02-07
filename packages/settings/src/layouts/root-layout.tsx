import * as React from 'react';

import { Outlet } from '@tanstack/react-router';
import { ErrorBoundary } from 'react-error-boundary';

import Error from '../components/error';
import Notice from '../components/notice';
import useNotices from '../hooks/use-notices';

import { Footer } from './footer';
import { NavSidebar } from './nav-sidebar';

export function RootLayout() {
	const { notice, setNotice } = useNotices();
	const [isMobileNavOpen, setIsMobileNavOpen] = React.useState(false);

	return (
		<div className="wcpos:flex wcpos:min-h-screen wcpos:bg-white">
			{/* Desktop sidebar (hidden on small screens via internal classes) */}
			<NavSidebar />

			{/* Mobile sidebar overlay */}
			<NavSidebar
				mobile
				isOpen={isMobileNavOpen}
				onClose={() => setIsMobileNavOpen(false)}
			/>

			<div className="wcpos:flex-1 wcpos:flex wcpos:flex-col wcpos:min-w-0">
				{/* Mobile menu button - only visible on small screens */}
				<div className="wcpos:lg:hidden wcpos:flex wcpos:items-center wcpos:p-4 wcpos:border-b wcpos:border-gray-200">
					<button
						type="button"
						onClick={() => setIsMobileNavOpen(true)}
						className="wcpos:p-2 wcpos:rounded-md wcpos:text-gray-600 hover:wcpos:bg-gray-100"
					>
						<svg className="wcpos:h-6 wcpos:w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
						</svg>
					</button>
					<span className="wcpos:ml-2 wcpos:font-semibold wcpos:text-gray-900">WooCommerce POS</span>
				</div>

				<main className="wcpos:flex-1 wcpos:max-w-3xl wcpos:w-full wcpos:mx-auto wcpos:px-6 wcpos:py-6">
					{notice && (
						<div className="wcpos:mb-4">
							<Notice status={notice.type} onRemove={() => setNotice(null)}>
								{notice.message}
							</Notice>
						</div>
					)}

					<ErrorBoundary FallbackComponent={Error}>
						<React.Suspense fallback={null}>
							<Outlet />
						</React.Suspense>
					</ErrorBoundary>

					<Footer />
				</main>
			</div>
		</div>
	);
}
