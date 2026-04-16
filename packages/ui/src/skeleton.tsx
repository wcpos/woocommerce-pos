import * as React from 'react';

import classNames from 'classnames';

export interface SkeletonProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'style'> {
	width?: string;
	height?: string;
	style?: React.CSSProperties;
}

/**
 * Base skeleton pulse element — a rounded gray bar that animates.
 *
 * Composed into page-specific skeletons (e.g. FormSkeleton, CardGridSkeleton)
 * in consumer packages. Keep this primitive minimal.
 */
export function Skeleton({ className, width, height, style, ...props }: SkeletonProps) {
	return (
		<div
			className={classNames(
				'wcpos:animate-pulse wcpos:rounded wcpos:bg-gray-200',
				className
			)}
			style={{ width, height, ...style }}
			{...props}
		/>
	);
}
