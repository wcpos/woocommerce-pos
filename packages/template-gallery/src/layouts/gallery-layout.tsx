import * as React from 'react';

import { Outlet, useSearch } from '@tanstack/react-router';
import { ErrorBoundary } from 'react-error-boundary';

import { GalleryGridSkeleton } from '../components/skeleton';
import { TypeTabs } from '../components/type-tabs';
import { getErrorMessage } from '../get-error-message';
import { t } from '../translations';

function ErrorFallback({
	error,
	resetErrorBoundary,
}: {
	error: unknown;
	resetErrorBoundary: () => void;
}) {
	const errorMessage = getErrorMessage(error);

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

const galleryCopy = {
	receipt: { title: 'layout.title', description: 'layout.description' },
	display: { title: 'layout.display_title', description: 'layout.display_description' },
	closure: { title: 'layout.closure_title', description: 'layout.closure_description' },
	report: { title: 'layout.report_title', description: 'layout.report_description' },
	email: { title: 'layout.email_title', description: 'layout.email_description' },
} as const;

export function GalleryLayout() {
	const { type } = useSearch({ from: '/' });
	return (
		<div className="wcpos:max-w-7xl">
			<TypeTabs activeType={type} />

			<div className="wcpos:mb-6">
				<h1 className="wcpos:text-2xl wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0">
					{t(galleryCopy[type].title)}
				</h1>
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mt-2">
					{t(galleryCopy[type].description)}{' '}
					{type !== 'email' && (
						<a
							href={
								type === 'display'
									? 'https://docs.wcpos.com/getting-started/free-vs-pro#customer-display'
									: 'https://docs.wcpos.com/receipts'
							}
							target="_blank"
							rel="noopener noreferrer"
							className="wcpos:text-wp-admin-theme-color wcpos:hover:underline"
						>
							{t('layout.learn_more')}
						</a>
					)}
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
