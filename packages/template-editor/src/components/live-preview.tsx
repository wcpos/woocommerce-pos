import { useMustachePreview } from '../hooks/use-mustache-preview';

interface LivePreviewProps {
	content: string;
	sampleData: Record<string, unknown>;
	previewUrl: string;
}

export function LivePreview({ content, sampleData, previewUrl }: LivePreviewProps) {
	const renderedHtml = useMustachePreview(content, sampleData);

	const srcdoc = `<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;">${renderedHtml}</body>
</html>`;

	return (
		<div className="wcpos:w-[360px] wcpos:shrink-0 wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-white">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					Preview
				</span>
				{previewUrl && (
					<a
						href={previewUrl}
						target="_blank"
						rel="noopener noreferrer"
						className="wcpos:text-xs wcpos:text-blue-600 hover:wcpos:underline"
					>
						Open in tab
					</a>
				)}
			</div>
			<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:flex wcpos:justify-center wcpos:p-4">
				<iframe
					srcDoc={srcdoc}
					sandbox="allow-same-origin"
					style={{ width: 400, border: '1px solid #ddd', background: '#fff', minHeight: 400 }}
					title="Template preview"
				/>
			</div>
		</div>
	);
}
