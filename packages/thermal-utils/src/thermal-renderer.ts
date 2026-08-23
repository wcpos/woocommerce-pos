/**
 * Thermal rendering utilities shared between template-editor and template-gallery.
 *
 * This renders the merchant-facing PREVIEW of a thermal template. The server
 * counterparts live in includes/Templates/Thermal/ — Thermal_Markup_Parser (the
 * same AST), Html_Thermal_Emitter (the PDF receipt) and Escpos_Thermal_Emitter
 * (what actually prints). What the merchant previews here has to be what prints
 * there, so attribute handling and layout bounds are kept term-for-term with
 * those files.
 *
 * Historical note: originally derived from @wcpos/printer/src/renderer/, which
 * no longer exists. Nothing in that path is a live reference.
 */
import Mustache from 'mustache';
import { generateBarcodeSvg, isQrBarcodeType } from './generate-barcode-svg';
import { sanitizeReceiptDataForRendering } from './receipt-data';

// -- AST Types --

type ThermalNode =
	| ReceiptNode
	| TextNode
	| RawTextNode
	| BoldNode
	| UnderlineNode
	| InvertNode
	| SizeNode
	| AlignNode
	| RowNode
	| ColNode
	| LineNode
	| BarcodeNode
	| QrcodeNode
	| ImageNode
	| CutNode
	| FeedNode
	| DrawerNode;

interface ReceiptNode {
	type: 'receipt';
	paperWidth: number;
	children: ThermalNode[];
}
interface RawTextNode {
	type: 'raw-text';
	value: string;
}
interface TextNode {
	type: 'text';
	children: ThermalNode[];
}
interface BoldNode {
	type: 'bold';
	children: ThermalNode[];
}
interface UnderlineNode {
	type: 'underline';
	children: ThermalNode[];
}
interface InvertNode {
	type: 'invert';
	children: ThermalNode[];
}
interface SizeNode {
	type: 'size';
	width: number;
	height: number;
	children: ThermalNode[];
}
interface AlignNode {
	type: 'align';
	mode: 'left' | 'center' | 'right';
	children: ThermalNode[];
}
interface RowNode {
	type: 'row';
	children: ColNode[];
}
interface ColNode {
	type: 'col';
	width: number | '*';
	align: 'left' | 'right';
	children: ThermalNode[];
}
interface LineNode {
	type: 'line';
	style: 'single' | 'dashed' | 'dotted' | 'double';
}
interface BarcodeNode {
	type: 'barcode';
	barcodeType: string;
	height: number;
	value: string;
}
interface QrcodeNode {
	type: 'qrcode';
	size: number;
	value: string;
}
interface ImageNode {
	type: 'image';
	src: string;
	width: number;
}
interface CutNode {
	type: 'cut';
	cutType: 'full' | 'partial';
}
interface FeedNode {
	type: 'feed';
	lines: number;
}
interface DrawerNode {
	type: 'drawer';
}

// -- XML Parser --

/**
 * The grammar PHP's is_numeric() accepts, so a string is a number on both sides
 * or on neither. Number() would also swallow '0x1A', '0b11' and 'Infinity',
 * which is_numeric() rejects.
 */
const DECIMAL_NUMERAL = /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/;

/**
 * The legal range of every numeric attribute in thermal markup.
 *
 * Mirrors includes/Templates/Thermal/Thermal_Bounds.php exactly. A template is
 * authored once and rendered down six paths — this preview, the PDF receipt and
 * the four wire emitters — so a bound that holds in only some of them is not a
 * safety net, it is a divergence: the merchant previews 50 blank lines and the
 * printer spools 500. Change a number here and change it there in the same
 * commit.
 */
const THERMAL_BOUNDS = {
	/** Narrowest and widest thermal roll, in character columns. */
	// Floor of 1, not the PDF page's 16: this bound is applied when parsing, so
	// it reaches every render path, and the plain-text lane legitimately renders
	// widths below 16. The ceiling is the part that matters here.
	paperWidth: { min: 1, max: 120 },
	/**
	 * Text size multiplier. 8 is the hardware ceiling: the ESC/POS `GS ! n` size
	 * byte carries one nibble per axis, and Star's magnification stops lower.
	 */
	sizeMultiplier: { min: 1, max: 8 },
	/** 1D barcode height in dots; `GS h n` is a single byte. */
	barcodeHeight: { min: 1, max: 255 },
	/** QR module size; the ESC/POS `GS ( k` module-size function accepts 1-16. */
	qrcodeSize: { min: 1, max: 16 },
	/** Image width in printer dots, past the 576-dot budget of an 80mm head. */
	imageWidthDots: { min: 1, max: 2000 },
	/**
	 * Lines a `<feed>` advances. At ~3.5mm per line the ceiling is ~17cm of
	 * blank paper, already more than any tear-off gap a template wants. It is
	 * also a hazard bound: every wire emitter turns `lines` straight into a loop
	 * or a str_repeat(), so an unbounded feed hangs the print request.
	 */
	feedLines: { min: 1, max: 50 },
	/** Fixed column width in characters; a column cannot outgrow the paper. */
	colWidth: { min: 1, max: 120 },
} as const;

/**
 * Clamp a value into an integer range, falling back when it is not numeric.
 *
 * Out-of-range values are clamped to the nearest bound rather than replaced by
 * the fallback, and the PHP twin, Thermal_Markup_Parser::int_attr(), does the
 * same. Fractions truncate toward zero.
 */
function safeInteger(value: unknown, fallback: number, min: number, max: number): number {
	const n =
		typeof value === 'number'
			? value
			: typeof value === 'string' && DECIMAL_NUMERAL.test(value.trim())
				? Number(value.trim())
				: NaN;
	return Number.isFinite(n) ? Math.max(min, Math.min(max, Math.trunc(n))) : fallback;
}

/**
 * Smallest `<size>` rendering, in em.
 *
 * Preview- and PDF-only, deliberately: CSS can express a half-size run where
 * the printers cannot, because the ESC/POS and Star size bytes have no
 * multiplier below 1. Parsed markup never reaches it (intAttr floors `<size>`
 * at THERMAL_BOUNDS.sizeMultiplier.min); it only applies to a hand-built AST.
 * Matches Html_Thermal_Emitter::MIN_SIZE_EM.
 */
const MIN_SIZE_EM = 0.5;

/** Clamp a number into a range without truncating it. */
function clamp(value: number, min: number, max: number): number {
	return Math.max(min, Math.min(max, value));
}

/** Round to 2dp, matching Html_Thermal_Emitter::format_float(). */
function round2(value: number): number {
	return Number(value.toFixed(2));
}

/**
 * Read a numeric attribute into its legal range.
 *
 * Every attribute read through here is a physical dimension, so the floor is at
 * least 1: `<size width="-2">` and `<feed lines="0">` are nonsense, and clamping
 * them up is what the printer does. The ceiling is per-attribute rather than
 * shared, because `<feed lines="1e15">` is a legal-looking numeral that the
 * wire emitters would turn into 10^15 line feeds.
 *
 * Keep in step with Thermal_Markup_Parser::int_attr() in PHP, which clamps
 * against the same table.
 */
function intAttr(
	el: Element,
	name: string,
	fallback: number,
	bounds: { readonly min: number; readonly max: number },
): number {
	const raw = el.getAttribute(name);
	if (raw == null) return fallback;
	return safeInteger(raw, fallback, bounds.min, bounds.max);
}

function enumAttr<T extends string>(
	el: Element,
	name: string,
	allowed: readonly T[],
	fallback: T,
): T {
	const value = el.getAttribute(name);
	return value && (allowed as readonly string[]).includes(value)
		? (value as T)
		: fallback;
}

/**
 * Convert a `<barcode>` pixel height into a QR module scale.
 *
 * A QR code written as `<barcode type="qr" height="40">` carries a pixel height
 * where a QR wants a module scale, so the height is folded into the scale the
 * `<qrcode size="...">` element would have used. Mirrored exactly by
 * `Thermal_Markup_Parser::height_to_qr_size()` so a QR is the same size on
 * screen as it is on paper.
 */
function heightToQrSize(height: number): number {
	if (height <= 0) return 4;
	return Math.max(2, Math.min(8, Math.round(height / 10)));
}

function parseChildren(parent: Element): ThermalNode[] {
	const nodes: ThermalNode[] = [];

	for (const child of Array.from(parent.childNodes)) {
		if (child.nodeType === 3) {
			const text = child.textContent ?? '';
			if (!text.trim()) continue;
			nodes.push({ type: 'raw-text', value: text });
			continue;
		}

		if (child.nodeType !== 1) continue;
		const el = child as Element;
		const tag = el.tagName.toLowerCase();

		switch (tag) {
			case 'text':
				nodes.push({ type: 'text', children: parseChildren(el) });
				break;
			case 'bold':
				nodes.push({ type: 'bold', children: parseChildren(el) });
				break;
			case 'underline':
				nodes.push({ type: 'underline', children: parseChildren(el) });
				break;
			case 'invert':
				nodes.push({ type: 'invert', children: parseChildren(el) });
				break;
			case 'size': {
				const w = intAttr(el, 'width', 1, THERMAL_BOUNDS.sizeMultiplier);
				nodes.push({
					type: 'size',
					width: w,
					height: intAttr(el, 'height', w, THERMAL_BOUNDS.sizeMultiplier),
					children: parseChildren(el),
				});
				break;
			}
			case 'align':
				nodes.push({
					type: 'align',
					mode: enumAttr(el, 'mode', ['left', 'center', 'right'] as const, 'left'),
					children: parseChildren(el),
				});
				break;
			case 'row':
				nodes.push({ type: 'row', children: parseRowChildren(el) } as RowNode);
				break;
			case 'col':
				break;
			case 'line':
				nodes.push({
					type: 'line',
					style: enumAttr(el, 'style', ['single', 'dashed', 'dotted', 'double'] as const, 'single'),
				});
				break;
			case 'barcode': {
				const barcodeType = el.getAttribute('type') ?? 'code128';
				// A QR type on a <barcode> element becomes a qrcode node, exactly
				// as Thermal_Markup_Parser::parse_children() does it. Left as a
				// barcode node the preview would size it by bwip-js scale 2 and
				// label it data-barcode-kind="barcode", so the merchant would see
				// a QR smaller than the one the printer produces.
				if (isQrBarcodeType(barcodeType)) {
					nodes.push({
						type: 'qrcode',
						// Same `height` attribute as the barcode branch below, so it
						// takes the same bound before heightToQrSize folds it into a
						// module scale — an unbounded read here would let a hand-built
						// AST route around the table every other attribute goes through.
						size: heightToQrSize(intAttr(el, 'height', 40, THERMAL_BOUNDS.barcodeHeight)),
						value: (el.textContent ?? '').trim(),
					});
					break;
				}
				nodes.push({
					type: 'barcode',
					barcodeType,
					height: intAttr(el, 'height', 40, THERMAL_BOUNDS.barcodeHeight),
					value: (el.textContent ?? '').trim(),
				});
				break;
			}
			case 'qrcode':
				nodes.push({
					type: 'qrcode',
					size: intAttr(el, 'size', 4, THERMAL_BOUNDS.qrcodeSize),
					value: (el.textContent ?? '').trim(),
				});
				break;
			case 'image':
				nodes.push({
					type: 'image',
					src: el.getAttribute('src') ?? '',
					width: intAttr(el, 'width', 200, THERMAL_BOUNDS.imageWidthDots),
				});
				break;
			case 'cut':
				nodes.push({
					type: 'cut',
					cutType: enumAttr(el, 'type', ['full', 'partial'] as const, 'partial'),
				});
				break;
			case 'feed':
				nodes.push({ type: 'feed', lines: intAttr(el, 'lines', 1, THERMAL_BOUNDS.feedLines) });
				break;
			case 'drawer':
				nodes.push({ type: 'drawer' });
				break;
			default:
				nodes.push(...parseChildren(el));
		}
	}

	return nodes;
}

function parseRowChildren(row: Element): ColNode[] {
	const cols: ColNode[] = [];
	for (const child of Array.from(row.children)) {
		if (child.tagName.toLowerCase() === 'col') {
			const rawWidth = child.getAttribute('width');
			cols.push({
				type: 'col',
				// width="*" is intentional gallery-template syntax: the column absorbs
				// whatever CPL remains after fixed columns. Do not collapse it to the
				// numeric fallback; doing so recreates the 12ch preview bug that made
				// 42/48-CPL thermal templates diverge between preview and ESC/POS output.
				width: rawWidth === '*' ? '*' : intAttr(child, 'width', 12, THERMAL_BOUNDS.colWidth),
				align: enumAttr(child, 'align', ['left', 'right'] as const, 'left'),
				children: parseChildren(child),
			});
		}
	}
	return cols;
}

function parseXml(xml: string): ReceiptNode {
	const doc = new DOMParser().parseFromString(xml, 'text/xml');

	const errorNode = doc.querySelector('parsererror');
	if (errorNode) {
		throw new Error(`XML parse error: ${errorNode.textContent}`);
	}

	const root = doc.documentElement;
	if (root.tagName !== 'receipt') {
		throw new Error(`Expected <receipt> root element, got <${root.tagName}>`);
	}

	return {
		type: 'receipt',
		paperWidth: intAttr(root, 'paper-width', 48, THERMAL_BOUNDS.paperWidth),
		children: parseChildren(root),
	};
}

// -- HTML Renderer --

function escapeHtml(str: string): string {
	return str
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;')
		.replace(/\//g, '&#x2F;');
}

function safeImageSrc(src: string): string {
	const trimmed = src.trim();
	if (!trimmed) return '';
	const cleaned = trimmed.replace(/[\u0000-\u001F\u007F\s]+/g, '');
	const normalized = cleaned.toLowerCase();
	const hasScheme = /^[a-z][a-z0-9+.-]*:/i.test(cleaned);
	const allowedAbsolute =
		normalized.startsWith('http://') ||
		normalized.startsWith('https://') ||
		normalized.startsWith('data:image/');
	const allowedRelative = !hasScheme && !normalized.startsWith('//');
	if (!allowedAbsolute && !allowedRelative) return '';
	return escapeHtml(cleaned);
}

function dotsToCh(dots: number, paperWidthChars: number): number {
	const dotBudget = paperWidthChars >= 40 ? 576 : 384;
	return (dots * paperWidthChars) / dotBudget;
}

function renderNodes(nodes: ThermalNode[], columns: number): string {
	return nodes.map((node) => renderNode(node, columns)).join('');
}

function renderNode(node: ThermalNode, columns: number): string {
	switch (node.type) {
		case 'raw-text':
			return escapeHtml(node.value);
		case 'text':
			return `<div>${renderNodes(node.children, columns)}</div>`;
		case 'bold':
			return `<strong>${renderNodes(node.children, columns)}</strong>`;
		case 'underline':
			return `<span style="text-decoration: underline">${renderNodes(node.children, columns)}</span>`;
		case 'invert':
			return `<span style="background: #000; color: #fff; padding: 0 4px">${renderNodes(node.children, columns)}</span>`;
		case 'size': {
			// The parser already bounded this; re-clamping keeps the render honest
			// for a hand-built AST, as Html_Thermal_Emitter::render_node() does.
			return `<span style="font-size: ${clamp(node.width, MIN_SIZE_EM, THERMAL_BOUNDS.sizeMultiplier.max)}em; line-height: 1.2; max-width: 100%; overflow-wrap: break-word; word-break: break-word">${renderNodes(node.children, columns)}</span>`;
		}
		case 'align':
			return `<div style="text-align: ${node.mode}">${renderNodes(node.children, columns)}</div>`;
		case 'row': {
			const widths = resolveRowWidths(node.children, columns);
			const cols = node.children
				.map((col, index) => renderCol(col, widths[index] ?? 0, columns))
				.join('');
			return `<div style="display: flex; max-width: 100%; overflow: hidden">${cols}</div>`;
		}
		case 'col':
			return renderCol(node, node.width === '*' ? 12 : node.width, columns);
		case 'line': {
			const borderTop = node.style === 'double'
				? '3px double #000'
				: `1px ${node.style === 'dashed' || node.style === 'dotted' ? node.style : 'solid'} #000`;
			return `<hr style="border: none; border-top: ${borderTop}; margin: 4px 0" />`;
		}
		case 'barcode':
			// node.height is in screen pixels (default 40); bwip-js height is in mm, so divide by 10.
			return generateBarcodeSvg(node.value, { type: node.barcodeType ?? 'code128', height: node.height / 10, scale: 2, kind: 'barcode', paperWidthChars: columns });
		case 'qrcode':
			return generateBarcodeSvg(node.value, { type: 'qrcode', scale: node.size, kind: 'qrcode', paperWidthChars: columns });
		case 'image': {
			const safeSrc = safeImageSrc(node.src);
			if (!safeSrc) return '';
			// Bounds mirror Html_Thermal_Emitter::render_image().
			const widthCh = dotsToCh(
				clamp(node.width, THERMAL_BOUNDS.imageWidthDots.min, THERMAL_BOUNDS.imageWidthDots.max),
				columns,
			);
			return `<div style="text-align: center; padding: 8px 0"><img src="${safeSrc}" style="width: min(100%, ${widthCh.toFixed(2)}ch); height: auto" /></div>`;
		}
		case 'cut':
			return '<div style="border-top: 1px dashed #ccc; margin: 12px 0; position: relative"><span style="position: absolute; top: -8px; left: -4px; font-size: 14px">&#9986;</span></div>';
		case 'feed':
			// Bounds mirror Html_Thermal_Emitter::render_node()'s clamp_integer call.
			// Rounded to 2dp so the value matches PHP's format_float(): 3 * 1.4 is
			// 4.199999999999999 in JS and "4.2" in PHP.
			return `<div style="height: ${round2(clamp(node.lines, THERMAL_BOUNDS.feedLines.min, THERMAL_BOUNDS.feedLines.max) * 1.4)}em"></div>`;
		case 'drawer':
			return '';
		case 'receipt':
			return renderNodes(node.children, node.paperWidth);
		default:
			return '';
	}
}

function resolveRowWidths(cols: readonly ColNode[], totalColumns: number): number[] {
	let fixedTotal = 0;
	let starCount = 0;

	for (const col of cols) {
		if (col.width === '*') {
			starCount++;
		} else {
			fixedTotal += col.width;
		}
	}

	// Keep this algorithm aligned with Escpos_Thermal_Emitter::resolve_row_widths()
	// in PHP: the printed output is what this preview has to match, and that is
	// where the same floor-divide lives. (Html_Thermal_Emitter, the PDF path, has
	// no star algebra at all — it hands `*` columns to Dompdf; see the note on its
	// render_row().)
	//
	// Fixed columns keep their explicit width, while width="*" columns share the
	// remaining printable cells for the active receipt CPL. Star columns are clamped to a
	// one-character minimum on purpose. If fixed columns already consume nearly
	// all available CPL, a row with multiple star columns is over-constrained;
	// shrinking a star to 0ch would silently delete a semantic column from the
	// preview. Letting the row overflow by the few impossible cells is the same
	// trade-off as the ESC/POS renderer: preserve authored columns, and surface
	// over-constrained templates in diagnostics/tests instead of pretending they
	// fit by making a column disappear.
	const remaining = Math.max(0, totalColumns - fixedTotal);
	const starWidth = starCount > 0 ? Math.floor(remaining / starCount) : 0;
	const starRemainder = starCount > 0 ? remaining - starWidth * starCount : 0;

	let starIndex = 0;
	return cols.map((col) => {
		if (col.width !== '*') return col.width;
		starIndex++;
		const extra = starIndex === starCount ? starRemainder : 0;
		return Math.max(1, starWidth + extra);
	});
}

function renderCol(node: ColNode, width: number, columns: number): string {
	// Bounds mirror Html_Thermal_Emitter::render_row_cell()'s clamp_integer call,
	// and the ESC/POS and Star row emitters bound their fixed columns the same
	// way, so a 121ch column does not wrap onto a second physical line there
	// while the preview shows one.
	return `<span style="flex: 0 0 ${clamp(width, THERMAL_BOUNDS.colWidth.min, THERMAL_BOUNDS.colWidth.max)}ch; min-width: 0; text-align: ${node.align}; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">${renderNodes(node.children, columns)}</span>`;
}

function renderHtml(ast: ReceiptNode): string {
	const width = ast.paperWidth;
	const inner = renderNodes(ast.children, ast.paperWidth);
	return `<div style="width: ${width}ch; font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.4; background: #fff; color: #000; padding: 16px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); margin: 0 auto; overflow: hidden; white-space: pre-wrap; word-break: break-all; box-sizing: content-box">${inner}</div>`;
}

// -- Public API --

export function renderThermalPreview(
	template: string,
	data: Record<string, unknown>,
): string {
	const resolved = Mustache.render(template, sanitizeReceiptDataForRendering(data));
	const ast = parseXml(resolved);
	return renderHtml(ast);
}
