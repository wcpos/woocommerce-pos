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

function numericAttribute(el: Element, name: string, fallback: number): number {
	const value = el.getAttribute(name);
	if (value === null) return fallback;

	const parsed = Number(value);
	return Number.isFinite(parsed) ? parsed : fallback;
}

function processBarcodeMarkers(html: string): string {
	const doc = new DOMParser().parseFromString(html, 'text/html');
	const markers = doc.querySelectorAll('[data-barcode], barcode, qrcode');

	if (markers.length === 0) {
		return html;
	}

	markers.forEach((el) => {
		const tagName = el.tagName.toLowerCase();
		const rawType = tagName === 'qrcode' ? 'qrcode' : el.getAttribute('data-barcode') || el.getAttribute('type') || 'qr';
		const type = rawType.trim().toLowerCase();
		const value = el.getAttribute('data-value') || el.textContent || '';
		const kind = type === 'qr' || type === 'qrcode' ? 'qrcode' : 'barcode';

		if (value.trim()) {
			el.outerHTML = generateBarcodeSvg(value, {
				type,
				kind,
				height: numericAttribute(el, 'height', 10),
				scale: numericAttribute(el, tagName === 'qrcode' ? 'size' : 'scale', kind === 'qrcode' ? 3 : 2),
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
