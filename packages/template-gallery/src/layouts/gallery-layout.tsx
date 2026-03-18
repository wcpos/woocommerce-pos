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

function ErrorFallback({
	error,
	resetErrorBoundary,
}: {
	error: unknown;
	resetErrorBoundary: () => void;
}) {
	const errorMessage = error instanceof Error ? error.message : String(error);

	return (
		<div className="wcpos:p-6 wcpos:text-center">
			<p className="wcpos:text-red-600 wcpos:mb-2">Failed to load templates.</p>
			<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-4">{errorMessage}</p>
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
	return (
		<div className="wcpos:max-w-7xl">
			<TypeTabs activeType="receipt" />

			<div className="wcpos:mb-6">
				<h1 className="wcpos:text-2xl wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0">
					Receipt Templates
				</h1>
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mt-2 wcpos:max-w-2xl">
					Design and manage the receipts printed at your point of sale.
					Templates use HTML or ESC/POS format and can be fully customised.{' '}
					<a
						href="https://docs.wcpos.com/templates/receipt-templates"
						target="_blank"
						rel="noopener noreferrer"
						className="wcpos:text-wp-admin-theme-color hover:wcpos:underline"
					>
						Learn more
					</a>
				</p>
			</div>

			<ErrorBoundary FallbackComponent={ErrorFallback}>
				<React.Suspense fallback={<LoadingFallback />}>
					<Outlet />
				</React.Suspense>
			</ErrorBoundary>
		</div>
	);
}
