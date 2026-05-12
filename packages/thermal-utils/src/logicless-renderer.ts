import Mustache from 'mustache';

import { generateBarcodeSvg } from './generate-barcode-svg';

function stripHtmlComments(template: string): string {
	let stripped = '';
	let cursor = 0;

	while (cursor < template.length) {
		const start = template.indexOf('<!--', cursor);
		if (start === -1) {
			return stripped + template.slice(cursor);
		}

		stripped += template.slice(cursor, start);

		const end = template.indexOf('-->', start + 4);
		if (end === -1) {
			return stripped;
		}

		cursor = end + 3;
	}

	return stripped;
}

function processBarcodeMarkers(html: string): string {
	const doc = new DOMParser().parseFromString(html, 'text/html');
	const markers = doc.querySelectorAll('[data-barcode]');

	if (markers.length === 0) {
		return html;
	}

	markers.forEach((el) => {
		const type = el.getAttribute('data-barcode') || 'qr';
		const value = el.getAttribute('data-value') || '';

		if (value) {
			el.outerHTML = generateBarcodeSvg(value, {
				type,
				kind: type === 'qr' || type === 'qrcode' ? 'qrcode' : 'barcode',
			});
		}
	});

	return doc.body.innerHTML;
}

export function renderLogiclessPreview(template: string, data: Record<string, unknown>): string {
	try {
		const rendered = Mustache.render(stripHtmlComments(template), data);
		return processBarcodeMarkers(rendered);
	} catch (error) {
		console.warn('Mustache rendering error:', error);
		return '<div style="color:red;padding:16px;">Template rendering error. Check your Mustache syntax.</div>';
	}
}
