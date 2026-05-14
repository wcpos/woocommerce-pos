/**
 * Generate an inline SVG string for a barcode or QR code.
 *
 * Uses bwip-js to render barcodes as self-contained SVG markup.
 * This is a pure function — no DOM dependencies — suitable for
 * embedding in HTML strings rendered inside sandboxed iframes.
 */
import * as bwipjs from 'bwip-js';

const BCID_ALIASES: Record<string, string> = { qr: 'qrcode' };

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
	const { type = 'qr', scale = 3, height = 10, kind = type === 'qr' || type === 'qrcode' ? 'qrcode' : 'barcode', paperWidthChars } = options;
	const text = value.trim();
	if (!text) return '';

	const bcid = BCID_ALIASES[type] ?? type;

	try {
		const svg = bwipjs.toSVG({
			bcid,
			text,
			scale: safeInteger(scale, 3, 1, 20),
			...(bcid === 'qrcode' ? {} : { height: safeInteger(height, 10, 1, 600) }),
			includetext: bcid !== 'qrcode',
		});
		return `<div data-barcode-kind="${kind}" data-barcode-value="${escapeHtml(text)}" style="text-align: center; padding: 8px 0">${constrainSvg(svg, paperWidthChars, kind)}</div>`;
	} catch (error) {
		return renderBarcodeError(kind, bcid, text, error);
	}
}
