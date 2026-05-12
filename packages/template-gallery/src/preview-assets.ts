const previewKeys = new Set([
	'detailed-receipt',
	'gift-receipt',
	'invoice',
	'minimal-receipt',
	'packing-slip',
	'quote',
	'standard-receipt',
	'thermal-detailed-58mm',
	'thermal-detailed-80mm',
	'thermal-kitchen-ticket',
	'thermal-simple-58mm',
	'thermal-simple-80mm',
	'thermal-style-receipt',
]);

function getPreviewBaseUrl(): string | undefined {
	return (window as Window & {
		wcpos?: { templateGallery?: { previewBaseUrl?: string } };
	}).wcpos?.templateGallery?.previewBaseUrl;
}

export function getGalleryPreviewSrc(templateKey: string): string | undefined {
	if (! previewKeys.has(templateKey)) {
		return undefined;
	}

	const previewBaseUrl = getPreviewBaseUrl();
	if (! previewBaseUrl) {
		return undefined;
	}

	return `${previewBaseUrl.replace(/\/$/, '')}/${templateKey}.png`;
}
