export type PreviewPaperWidth = '58mm' | '80mm' | 'a4';

interface BuildPreviewFrameHtmlOptions {
	bodyHtml: string;
	paperWidth?: string | null;
}

export function normalizePreviewPaperWidth(value: string | null | undefined): PreviewPaperWidth {
	const normalized = value?.trim().toLowerCase();
	if (normalized === '58mm' || normalized === '80mm' || normalized === 'a4') return normalized;
	return 'a4';
}

export function buildPreviewFrameHtml({ bodyHtml, paperWidth }: BuildPreviewFrameHtmlOptions): string {
	const normalized = normalizePreviewPaperWidth(paperWidth);
	const paperWidthCss = normalized === 'a4' ? '210mm' : normalized;
	// No fixed paper height: the preview viewport measures and sizes to the
	// rendered content, so a 297mm A4 min-height would only add empty space.

	return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
html,body{margin:0;padding:0;background:#fff;color:#000;}
body{overflow:auto;}
.wcpos-preview-paper{width:${paperWidthCss};background:#fff;color:#000;}
</style>
</head>
<body><div class="wcpos-preview-paper">${bodyHtml}</div></body>
</html>`;
}
