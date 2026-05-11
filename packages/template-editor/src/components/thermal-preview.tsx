import { buildPreviewFrameHtml, renderThermalPreview } from '@wcpos/thermal-utils';

import { useThermalPreview } from '../hooks/use-thermal-preview';
import { t } from '../translations';
import { PreviewSkeleton } from './preview-skeleton';
import type { ReactNode } from 'react';

interface ThermalPreviewProps {
	content: string;
	sampleData: Record<string, unknown>;
	loading?: boolean;
	sourcePicker?: ReactNode;
	paperWidth?: string | null;
}

interface BuildThermalPreviewSrcDocOptions {
	content: string;
	sampleData: Record<string, unknown>;
	paperWidth?: string | null;
	bodyHtml?: string;
}

export function buildThermalPreviewSrcDoc({ content, sampleData, paperWidth, bodyHtml }: BuildThermalPreviewSrcDocOptions): string {
	return buildPreviewFrameHtml({
		bodyHtml: bodyHtml ?? renderThermalPreview(content, sampleData),
		paperWidth: paperWidth ?? inferPaperWidthFromXml(content),
	});
}

export function ThermalPreview({ content, sampleData, loading, sourcePicker, paperWidth }: ThermalPreviewProps) {
	const renderedHtml = useThermalPreview(content, sampleData);
	const srcdoc = buildThermalPreviewSrcDoc({
		content,
		sampleData,
		paperWidth,
		bodyHtml: renderedHtml,
	});

	return (
		<div className="wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-white">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.thermal_preview')}
				</span>
				{sourcePicker}
			</div>
			<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:flex wcpos:justify-center wcpos:p-4">
				{loading ? (
					<PreviewSkeleton style={{ width: '100%', maxWidth: 420, minHeight: 400 }} />
				) : (
					<iframe
						srcDoc={srcdoc}
						sandbox="allow-same-origin"
						style={{
							width: '100%',
							maxWidth: 520,
							border: 'none',
							background: '#f5f5f5',
							minHeight: 400,
						}}
						title={t('editor.thermal_template_preview')}
					/>
				)}
			</div>
		</div>
	);
}

function inferPaperWidthFromXml(content: string): string | null {
	const match = content.match(/<receipt\b[^>]*\bpaper-width=["']?(32|42|48|58mm|80mm)/i);
	const value = match?.[1]?.toLowerCase();
	if (value === '32' || value === '58mm') return '58mm';
	if (value === '42' || value === '48' || value === '80mm') return '80mm';
	return null;
}
