/**
 * Generate an inline SVG string for a barcode or QR code.
 *
 * Uses bwip-js to render barcodes as self-contained SVG markup.
 * This is a pure function — no DOM dependencies — suitable for
 * embedding in HTML strings rendered inside sandboxed iframes.
 */
import * as bwipjs from 'bwip-js';

type BarcodeType = 'qr' | 'code128' | 'ean13' | 'code39';

const BCID_MAP: Record<BarcodeType, string> = {
	qr: 'qrcode',
	code128: 'code128',
	ean13: 'ean13',
	code39: 'code39',
};

interface BarcodeOptions {
	type?: BarcodeType;
	scale?: number;
	height?: number;
}

export function generateBarcodeSvg(
	value: string,
	options: BarcodeOptions = {},
): string {
	const { type = 'qr', scale = 3, height = 10 } = options;

	if (!value.trim()) {
		return '';
	}

	const bcid = BCID_MAP[type] ?? 'qrcode';

	try {
		const svg = bwipjs.toSVG({
			bcid,
			text: value,
			scale,
			height,
			includetext: type !== 'qr',
		});
		return `<div style="text-align: center; padding: 8px 0">${svg}</div>`;
	} catch (error) {
		console.warn(`Barcode generation failed for type="${type}":`, error);
		return `<div style="text-align: center; padding: 8px 0; color: #999; font-size: 10px">Barcode error</div>`;
	}
}
