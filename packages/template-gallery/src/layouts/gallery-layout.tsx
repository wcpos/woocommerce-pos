import * as React from 'react';
import { Outlet } from '@tanstack/react-router';
import { ErrorBoundary } from 'react-error-boundary';

import { GalleryGridSkeleton } from '../components/skeleton';
import { TypeTabs } from '../components/type-tabs';
import { t } from '../translations';

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
			<p className="wcpos:text-red-600 wcpos:mb-2">{t('layout.error')}</p>
			<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-4">{errorMessage}</p>
			<button
				type="button"
				onClick={resetErrorBoundary}
				className="wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:border-0 wcpos:rounded wcpos:cursor-pointer"
			>
				{t('layout.try_again')}
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
					{t('layout.title')}
				</h1>
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mt-2">
					{t('layout.description')}{' '}
					<a
						href="https://docs.wcpos.com/templates/receipt-templates"
						target="_blank"
						rel="noopener noreferrer"
						className="wcpos:text-wp-admin-theme-color hover:wcpos:underline"
					>
						{t('layout.learn_more')}
					</a>
				</p>
			</div>

			<ErrorBoundary FallbackComponent={ErrorFallback}>
				<React.Suspense fallback={<GalleryGridSkeleton />}>
					<Outlet />
				</React.Suspense>
			</ErrorBoundary>
		</div>
	);
}
