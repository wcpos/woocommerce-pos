import { buildPreviewFrameHtml } from '@wcpos/thermal-utils';

import { useMustachePreview } from '../hooks/use-mustache-preview';
import { t } from '../translations';
import { PreviewSkeleton } from './preview-skeleton';
import type { CSSProperties, ReactNode } from 'react';

interface LivePreviewProps {
	content: string;
	sampleData: Record<string, unknown>;
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
		minHeight: 560,
	};
}

export function getPreviewBodyClassName(): string {
	return 'wcpos:flex-1 wcpos:overflow-auto wcpos:p-0 wcpos:bg-gray-50';
}

export function LivePreview({ content, sampleData, loading, sourcePicker }: LivePreviewProps) {
	const renderedHtml = useMustachePreview(content, sampleData);
	const srcdoc = buildLivePreviewSrcDoc(renderedHtml);

	return (
		<div className="wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:flex wcpos:flex-col wcpos:rounded-lg wcpos:overflow-hidden">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-gray-50">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.preview')}
				</span>
				<div className="wcpos:flex wcpos:items-center wcpos:gap-3">
					{sourcePicker}
				</div>
			</div>
			<div className={getPreviewBodyClassName()}>
				{loading ? (
					<PreviewSkeleton style={{ width: '100%', minHeight: 560 }} />
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
