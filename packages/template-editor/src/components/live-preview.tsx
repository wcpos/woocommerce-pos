import { useMustachePreview } from '../hooks/use-mustache-preview';
import { t } from '../translations';
import { PreviewSkeleton } from './preview-skeleton';
import type { ReactNode } from 'react';

interface LivePreviewProps {
	content: string;
	sampleData: Record<string, unknown>;
	previewUrl: string;
	loading?: boolean;
	sourcePicker?: ReactNode;
}

export function LivePreview({ content, sampleData, previewUrl, loading, sourcePicker }: LivePreviewProps) {
	const renderedHtml = useMustachePreview(content, sampleData);

	const srcdoc = `<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;">${renderedHtml}</body>
</html>`;

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
			<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:flex wcpos:justify-center wcpos:p-4">
				{loading ? (
					<PreviewSkeleton style={{ width: '100%', maxWidth: 400, minHeight: 400 }} />
				) : (
					<iframe
						srcDoc={srcdoc}
						sandbox="allow-same-origin"
						style={{ width: '100%', maxWidth: 400, border: '1px solid #ddd', background: '#fff', minHeight: 400 }}
						title={t('editor.template_preview')}
					/>
				)}
			</div>
		</div>
	);
}
