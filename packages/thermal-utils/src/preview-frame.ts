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
	const paperMinHeight = normalized === 'a4' ? '297mm' : 'auto';

	return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
html,body{margin:0;padding:0;background:#f5f5f5;color:#000;}
body{min-height:100vh;padding:24px;overflow:auto;}
.wcpos-preview-viewport{min-width:max-content;display:flex;justify-content:center;align-items:flex-start;}
.wcpos-preview-paper{width:${paperWidthCss};min-height:${paperMinHeight};background:#fff;color:#000;}
</style>
</head>
<body><div class="wcpos-preview-viewport"><div class="wcpos-preview-paper">${bodyHtml}</div></div></body>
</html>`;
}
