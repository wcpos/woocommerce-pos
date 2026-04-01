import { useState, useEffect, useRef } from 'react';
import Mustache from 'mustache';
import { generateBarcodeSvg } from '../lib/generate-barcode-svg';

/**
 * Post-process rendered HTML to replace data-barcode marker elements
 * with inline SVG barcodes.
 *
 * Markers use the format:
 *   <div data-barcode="qr" data-value="payload"></div>
 *   <div data-barcode="code128" data-value="12345"></div>
 */
function processBarcodeMarkers(html: string): string {
	const doc = new DOMParser().parseFromString(html, 'text/html');
	const markers = doc.querySelectorAll('[data-barcode]');

	if (markers.length === 0) {
		return html;
	}

	markers.forEach((el) => {
		const type = (el.getAttribute('data-barcode') || 'qr') as 'qr' | 'code128' | 'ean13' | 'code39';
		const value = el.getAttribute('data-value') || '';
		if (value) {
			el.innerHTML = generateBarcodeSvg(value, { type });
		}
	});

	return doc.body.innerHTML;
}

export function useMustachePreview(template: string, data: Record<string, unknown>, debounceMs = 300) {
	const [html, setHtml] = useState('');
	const timeoutRef = useRef<ReturnType<typeof setTimeout>>();

	useEffect(() => {
		if (timeoutRef.current) {
			clearTimeout(timeoutRef.current);
		}

		timeoutRef.current = setTimeout(() => {
			try {
				const rendered = Mustache.render(template, data);
				setHtml(processBarcodeMarkers(rendered));
			} catch (error) {
				console.warn('Mustache rendering error:', error);
				setHtml('<div style="color:red;padding:16px;">Template rendering error. Check your Mustache syntax.</div>');
			}
		}, debounceMs);

		return () => {
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}
		};
	}, [template, data, debounceMs]);

	return html;
}
