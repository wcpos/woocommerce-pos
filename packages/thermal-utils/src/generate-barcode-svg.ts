/**
 * Generate an inline SVG string for a barcode or QR code.
 *
 * Uses bwip-js to render barcodes as self-contained SVG markup.
 * This is a pure function — no DOM dependencies — suitable for
 * embedding in HTML strings rendered inside sandboxed iframes.
 */
import {
	code128,
	code39,
	code93,
	ean13,
	ean8,
	upca,
	upce,
	rationalizedCodabar,
	interleaved2of5,
	qrcode,
	drawingSVG,
	type RenderOptions,
} from 'bwip-js';

/**
 * Barcode `type` (from the template markup) → bwip-js encoder.
 *
 * The encoders are imported individually rather than through the generic
 * `toSVG()`, which links every BWIPP symbology and so cannot be tree-shaken.
 * Shipping only the symbologies we actually offer trims ~1MB of BWIPP down to
 * the handful below. The keys mirror the server-side picqer map in
 * `Barcode_Image.php` so the on-screen preview matches the printed output —
 * and, like that map, an unknown type falls back to Code 128.
 */
interface BarcodeSpec {
	bcid: string;
	encode: (opts: RenderOptions) => string;
}

const BARCODE_SPECS: Record<string, BarcodeSpec> = {
	code128: { bcid: 'code128', encode: (opts) => code128(opts, drawingSVG()) },
	code39: { bcid: 'code39', encode: (opts) => code39(opts, drawingSVG()) },
	code93: { bcid: 'code93', encode: (opts) => code93(opts, drawingSVG()) },
	ean13: { bcid: 'ean13', encode: (opts) => ean13(opts, drawingSVG()) },
	ean8: { bcid: 'ean8', encode: (opts) => ean8(opts, drawingSVG()) },
	upca: { bcid: 'upca', encode: (opts) => upca(opts, drawingSVG()) },
	upce: { bcid: 'upce', encode: (opts) => upce(opts, drawingSVG()) },
	codabar: {
		bcid: 'rationalizedCodabar',
		encode: (opts) => rationalizedCodabar(opts, drawingSVG()),
	},
	itf: { bcid: 'interleaved2of5', encode: (opts) => interleaved2of5(opts, drawingSVG()) },
	qr: { bcid: 'qrcode', encode: (opts) => qrcode(opts, drawingSVG()) },
	qrcode: { bcid: 'qrcode', encode: (opts) => qrcode(opts, drawingSVG()) },
};

/**
 * Whether a template's barcode `type` names the QR symbology rather than a 1D one.
 *
 * `<barcode type="qr">` is a QR code wearing a barcode element's attributes, and
 * every lane has to recognise that before it sizes or labels the symbol. Mirrors
 * `Barcode_Symbology::is_qr()` on the PHP side.
 */
export function isQrBarcodeType(type: string): boolean {
	const normalized = type.trim().toLowerCase();
	return normalized === 'qr' || normalized === 'qrcode';
}

interface BarcodeOptions {
	type?: string;
	scale?: number;
	height?: number;
	kind?: 'barcode' | 'qrcode';
	paperWidthChars?: number;
}

function escapeHtml(str: string): string {
	return str
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

function safeInteger(value: unknown, fallback: number, min: number, max: number): number {
	const n = typeof value === 'number' ? value : Number(value);
	return Number.isFinite(n) ? Math.max(min, Math.min(max, Math.trunc(n))) : fallback;
}

const DOT_BUDGET_WIDE = 576;
const DOT_BUDGET_NARROW = 384;
const NARROW_PAPER_THRESHOLD_CHARS = 40;
const BARCODE_PREVIEW_SCALE = 1.5;

function dotsToCh(dots: number, paperWidthChars: number): number {
	const dotBudget = paperWidthChars >= NARROW_PAPER_THRESHOLD_CHARS ? DOT_BUDGET_WIDE : DOT_BUDGET_NARROW;
	return (dots * paperWidthChars) / dotBudget;
}

function constrainSvg(svg: string, paperWidthChars: number | undefined, kind: 'barcode' | 'qrcode'): string {
	if (paperWidthChars === undefined) {
		return svg.replace(/<svg\b/, '<svg style="max-width: 100%; height: auto"');
	}

	const widthMatch = svg.match(/\bwidth="([\d.]+)pt"/);
	const viewBoxMatch = svg.match(/\bviewBox="0 0 ([\d.]+) ([\d.]+)"/);
	const naturalWidth = widthMatch ? Number(widthMatch[1]) : viewBoxMatch ? Number(viewBoxMatch[1]) : 0;
	const widthCh = Number.isFinite(naturalWidth) && naturalWidth > 0
		? dotsToCh(naturalWidth, paperWidthChars) * (kind === 'barcode' ? BARCODE_PREVIEW_SCALE : 1)
		: paperWidthChars;

	return svg.replace(/<svg\b/, `<svg style="width: min(100%, ${widthCh.toFixed(2)}ch); height: auto"`);
}

function renderBarcodeError(kind: 'barcode' | 'qrcode', barcodeType: string, text: string, error: unknown): string {
	const title = kind === 'qrcode' ? 'QR code error' : 'Barcode error';
	const normalizedType = barcodeType.trim().toLowerCase() || kind;
	const summary = kind === 'qrcode' ? 'Invalid QR code value' : `Invalid ${normalizedType} barcode value`;
	const detail = error instanceof Error && error.message.trim() ? error.message.trim() : '';
	const detailHtml = detail ? `<div style="font-size: 0.9em">${escapeHtml(detail)}</div>` : '';

	return `<div data-barcode-kind="${kind}" data-barcode-value="${escapeHtml(text)}" data-barcode-error="true" style="text-align: center; padding: 8px 0; color: #b91c1c"><strong>${title}</strong><div>${escapeHtml(summary)}</div>${detailHtml}<code>${escapeHtml(text)}</code></div>`;
}

export function generateBarcodeSvg(value: string, options: BarcodeOptions = {}): string {
	const { type = 'qr', scale = 3, height = 10, kind: requestedKind, paperWidthChars } = options;
	const normalizedType = type.trim().toLowerCase();
	const kind = requestedKind ?? (isQrBarcodeType(normalizedType) ? 'qrcode' : 'barcode');
	const text = value.trim();
	if (!text) return '';

	const spec = BARCODE_SPECS[normalizedType] ?? BARCODE_SPECS.code128;
	const isQr = spec.bcid === 'qrcode';

	try {
		const svg = spec.encode({
			bcid: spec.bcid,
			text,
			scale: safeInteger(scale, 3, 1, 20),
			...(isQr ? {} : { height: safeInteger(height, 10, 1, 600) }),
			includetext: !isQr,
		});
		return `<div data-barcode-kind="${kind}" data-barcode-value="${escapeHtml(text)}" style="text-align: center; padding: 8px 0">${constrainSvg(svg, paperWidthChars, kind)}</div>`;
	} catch (error) {
		// Show the type the merchant chose (e.g. "itf"), not the internal BWIPP
		// encoder name (e.g. "interleaved2of5") that spec.bcid resolves to.
		return renderBarcodeError(kind, type, text, error);
	}
}
