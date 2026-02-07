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

	return (
		<div className="wcpos:flex wcpos:min-h-screen wcpos:bg-white">
			<NavSidebar />

			<div className="wcpos:flex-1 wcpos:flex wcpos:flex-col wcpos:min-w-0">
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
