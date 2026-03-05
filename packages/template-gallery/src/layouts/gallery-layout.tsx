import * as React from 'react';
import { Outlet } from '@tanstack/react-router';
import { ErrorBoundary } from 'react-error-boundary';

import { TypeTabs } from '../components/type-tabs';

function LoadingFallback() {
	return (
		<div className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:py-16">
			<div className="wcpos:text-gray-400 wcpos:text-sm">Loading templates...</div>
		</div>
	);
}

function ErrorFallback({ error, resetErrorBoundary }: { error: Error; resetErrorBoundary: () => void }) {
	return (
		<div className="wcpos:p-6 wcpos:text-center">
			<p className="wcpos:text-red-600 wcpos:mb-2">Failed to load templates.</p>
			<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-4">{error.message}</p>
			<button
				type="button"
				onClick={resetErrorBoundary}
				className="wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:border-0 wcpos:rounded wcpos:cursor-pointer"
			>
				Try Again
			</button>
		</div>
	);
}

export function GalleryLayout() {
	const [activeType, setActiveType] = React.useState('receipt');

	return (
		<div className="wcpos:max-w-7xl">
			<div className="wcpos:mb-6">
				<h1 className="wcpos:text-2xl wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0">
					Receipt Templates
				</h1>
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mt-1">
					Customise your POS receipts.
				</p>
			</div>

			<TypeTabs activeType={activeType} onChange={setActiveType} />

			<ErrorBoundary FallbackComponent={ErrorFallback}>
				<React.Suspense fallback={<LoadingFallback />}>
					<Outlet />
				</React.Suspense>
			</ErrorBoundary>
		</div>
	);
}
