import { buildPreviewFrameHtml } from '@wcpos/thermal-utils';

import { useMustachePreview } from '../hooks/use-mustache-preview';
import { t } from '../translations';
import { PreviewSkeleton } from './preview-skeleton';
import type { CSSProperties, ReactNode } from 'react';

interface LivePreviewProps {
	content: string;
	sampleData: Record<string, unknown>;
	previewUrl: string;
	loading?: boolean;
	sourcePicker?: ReactNode;
}

export function buildLivePreviewSrcDoc(renderedHtml: string): string {
	return buildPreviewFrameHtml({ bodyHtml: renderedHtml, paperWidth: 'a4' });
}

export function getPreviewIframeStyle(): CSSProperties {
	return {
		width: '100%',
		border: '1px solid #ddd',
		background: '#f5f5f5',
		minHeight: 400,
	};
}

export function LivePreview({ content, sampleData, previewUrl, loading, sourcePicker }: LivePreviewProps) {
	const renderedHtml = useMustachePreview(content, sampleData);
	const srcdoc = buildLivePreviewSrcDoc(renderedHtml);

	return (
		<div className="wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-white">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.preview')}
				</span>
				<div className="wcpos:flex wcpos:items-center wcpos:gap-3">
					{sourcePicker}
					{previewUrl && (
						<a
							href={previewUrl}
							target="_blank"
							rel="noopener noreferrer"
							className="wcpos:text-xs wcpos:text-blue-600 hover:wcpos:underline"
						>
							{t('editor.open_in_tab')}
						</a>
					)}
				</div>
			</div>
			<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:p-4">
				{loading ? (
					<PreviewSkeleton style={{ width: '100%', minHeight: 400 }} />
				) : (
					<iframe
						srcDoc={srcdoc}
						sandbox="allow-same-origin"
						style={getPreviewIframeStyle()}
						title={t('editor.template_preview')}
					/>
				)}
			</div>
		</div>
	);
}
