import { useThermalPreview } from '../hooks/use-thermal-preview';
import { t } from '../translations';

interface ThermalPreviewProps {
	content: string;
	sampleData: Record<string, unknown>;
}

export function ThermalPreview({ content, sampleData }: ThermalPreviewProps) {
	const renderedHtml = useThermalPreview(content, sampleData);

	const srcdoc = `<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f5;display:flex;justify-content:center;">${renderedHtml}</body>
</html>`;

	return (
		<div className="wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-white">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.thermal_preview')}
				</span>
			</div>
			<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:flex wcpos:justify-center wcpos:p-4">
				<iframe
					srcDoc={srcdoc}
					sandbox="allow-same-origin"
					style={{
						width: '100%',
						maxWidth: 420,
						border: 'none',
						background: '#f5f5f5',
						minHeight: 400,
					}}
					title={t('editor.thermal_template_preview')}
				/>
			</div>
		</div>
	);
}
