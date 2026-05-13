import { buildPreviewFrameHtml, renderThermalPreview } from '@wcpos/thermal-utils';

import { useThermalPreview } from '../hooks/use-thermal-preview';
import { t } from '../translations';
import { PreviewSkeleton } from './preview-skeleton';
import type { CSSProperties, ReactNode } from 'react';

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

export function getThermalPreviewBodyClassName(): string {
	return 'wcpos:flex-1 wcpos:overflow-auto wcpos:flex wcpos:justify-center wcpos:p-0 wcpos:bg-gray-50';
}

export function getThermalPreviewIframeStyle(): CSSProperties {
	return {
		width: '100%',
		maxWidth: 520,
		border: 'none',
		background: '#f5f5f5',
		minHeight: 560,
	};
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
		<div className="wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:flex wcpos:flex-col wcpos:rounded-lg wcpos:overflow-hidden">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-gray-50">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.thermal_preview')}
				</span>
				{sourcePicker}
			</div>
			<div className={getThermalPreviewBodyClassName()}>
				{loading ? (
					<PreviewSkeleton style={{ width: '100%', maxWidth: 520, minHeight: 560 }} />
				) : (
					<iframe
						srcDoc={srcdoc}
						sandbox="allow-same-origin"
						style={getThermalPreviewIframeStyle()}
						title={t('editor.thermal_template_preview')}
					/>
				)}
			</div>
		</div>
	);
}

function inferPaperWidthFromXml(content: string): string | null {
	const match = content.match(/<receipt\b[^>]*\bpaper-width\s*=\s*(["'])(32|42|48|58|80)(?:mm)?\1/i);
	const value = match?.[2]?.toLowerCase();
	if (value === '32' || value === '58') return '58mm';
	if (value === '42' || value === '48' || value === '80') return '80mm';
	return null;
}
