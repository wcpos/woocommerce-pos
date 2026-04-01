import type { CSSProperties } from 'react';
import { t } from '../translations';

interface PreviewSkeletonProps {
	style?: CSSProperties;
}

export function PreviewSkeleton({ style }: PreviewSkeletonProps) {
	return (
		<div
			style={{
				background: '#fff',
				border: '1px solid #ddd',
				display: 'flex',
				flexDirection: 'column',
				alignItems: 'center',
				justifyContent: 'center',
				gap: 12,
				...style,
			}}
		>
			<svg
				className="wcpos:animate-spin"
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
			>
				<circle cx="12" cy="12" r="10" stroke="#e5e7eb" strokeWidth="3" />
				<path
					d="M12 2a10 10 0 0 1 10 10"
					stroke="var(--wp-admin-theme-color, #007cba)"
					strokeWidth="3"
					strokeLinecap="round"
				/>
			</svg>
			<span className="wcpos:text-xs wcpos:text-gray-400">
				{t('editor.loading_data')}
			</span>
		</div>
	);
}
