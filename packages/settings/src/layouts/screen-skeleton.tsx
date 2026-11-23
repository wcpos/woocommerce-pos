import * as React from 'react';

import { useLocation } from '@tanstack/react-router';

import {
	FormSkeleton,
	CardGridSkeleton,
	ListSkeleton,
	AccessSkeleton,
	SessionsSkeleton,
	LicenseSkeleton,
} from '../components/skeleton';

const screenSkeletons: Record<string, React.ReactNode> = {
	'/general': <FormSkeleton rows={5} />,
	'/checkout': <FormSkeleton rows={4} />,
	'/cloud-print': <FormSkeleton rows={4} />,
	'/access': <AccessSkeleton />,
	'/sessions': <SessionsSkeleton />,
	'/extensions': <CardGridSkeleton cards={4} />,
	'/export-closures': <FormSkeleton rows={1} />,
	'/logs': <ListSkeleton rows={8} />,
	'/license': <LicenseSkeleton />,
};

/**
 * The loading skeleton for the current screen. The router renders it as every route's
 * pending UI: each route sits in its own Suspense boundary, which catches a screen that
 * is loading before the layout's boundary can.
 */
export function ScreenSkeleton() {
	const { pathname } = useLocation();
	return screenSkeletons[pathname] || <FormSkeleton />;
}
