import * as React from 'react';

import classNames from 'classnames';

export type AvatarSize = 'sm' | 'md' | 'lg';
export type AvatarRing = 'none' | 'brand';
export type AvatarStatus = 'none' | 'active';

export interface AvatarProps {
	/** The person's name. Used for alt text and initials fallback. */
	name: string;
	/** Optional image URL. Falls back to initials if missing or fails to load. */
	src?: string;
	/** Visual size (sm=32px, md=40px, lg=48px). */
	size?: AvatarSize;
	/** Optional theme-color ring (e.g. for selected/"you" state). */
	ring?: AvatarRing;
	/** Optional status indicator overlay. */
	status?: AvatarStatus;
	/** Accessible label for the status indicator, when status != "none". */
	statusLabel?: string;
	className?: string;
}

const SIZE_CLASSES: Record<AvatarSize, string> = {
	sm: 'wcpos:w-8 wcpos:h-8 wcpos:text-xs',
	md: 'wcpos:w-10 wcpos:h-10 wcpos:text-sm',
	lg: 'wcpos:w-12 wcpos:h-12 wcpos:text-base',
};

const STATUS_DOT_SIZE: Record<AvatarSize, string> = {
	sm: 'wcpos:w-2.5 wcpos:h-2.5',
	md: 'wcpos:w-3 wcpos:h-3',
	lg: 'wcpos:w-3.5 wcpos:h-3.5',
};

const RING_CLASSES: Record<AvatarRing, string> = {
	none: 'wcpos:ring-1 wcpos:ring-gray-200',
	brand: 'wcpos:ring-2 wcpos:ring-wp-admin-theme-color',
};

/**
 * Derive up-to-two-letter initials from a name. Uses first letter of the first
 * word plus first letter of the last word; single-word names use just the first
 * letter. Returns "?" for empty input.
 */
export function getInitials(name: string): string {
	const trimmed = (name || '').trim();
	if (!trimmed) return '?';
	const parts = trimmed.split(/\s+/);
	if (parts.length === 1) {
		return parts[0].charAt(0).toUpperCase();
	}
	const first = parts[0].charAt(0);
	const last = parts[parts.length - 1].charAt(0);
	return (first + last).toUpperCase();
}

export function Avatar({
	name,
	src,
	size = 'md',
	ring = 'none',
	status = 'none',
	statusLabel,
	className,
}: AvatarProps) {
	const [hasError, setHasError] = React.useState(false);
	const showImage = Boolean(src) && !hasError;

	// Reset error state if the src changes to a new URL.
	React.useEffect(() => {
		setHasError(false);
	}, [src]);

	return (
		<span
			className={classNames(
				'wcpos:relative wcpos:inline-flex wcpos:shrink-0',
				className
			)}
		>
			<span
				className={classNames(
					'wcpos:inline-flex wcpos:items-center wcpos:justify-center',
					'wcpos:rounded-full wcpos:overflow-hidden wcpos:select-none',
					'wcpos:bg-gray-100 wcpos:text-gray-700 wcpos:font-semibold',
					SIZE_CLASSES[size],
					RING_CLASSES[ring]
				)}
				role={showImage ? undefined : 'img'}
				aria-label={showImage ? undefined : name}
			>
				{showImage ? (
					<img
						src={src}
						alt={name}
						className="wcpos:w-full wcpos:h-full wcpos:object-cover"
						onError={() => setHasError(true)}
					/>
				) : (
					<span aria-hidden="true">{getInitials(name)}</span>
				)}
			</span>
			{status === 'active' && (
				<span
					className={classNames(
						'wcpos:absolute wcpos:-bottom-0.5 wcpos:-right-0.5',
						'wcpos:rounded-full wcpos:bg-green-500 wcpos:ring-2 wcpos:ring-white',
						STATUS_DOT_SIZE[size]
					)}
					role="status"
					aria-label={statusLabel || 'Active now'}
					title={statusLabel || 'Active now'}
				/>
			)}
		</span>
	);
}
