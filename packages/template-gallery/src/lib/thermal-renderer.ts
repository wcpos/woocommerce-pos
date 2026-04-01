/**
 * Self-contained thermal XML template -> HTML preview renderer.
 *
 * This is a copy of the pure rendering functions from @wcpos/printer/src/renderer/.
 * When @wcpos/printer is published to npm, replace this with an import from that package.
 */
import Mustache from 'mustache';
import { generateBarcodeSvg } from './generate-barcode-svg';

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
	width: number;
	align: 'left' | 'right';
	children: ThermalNode[];
}
interface LineNode {
	type: 'line';
	style: 'single' | 'double';
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

function intAttr(el: Element, name: string, fallback: number): number {
	const raw = el.getAttribute(name);
	if (raw == null) return fallback;
	const n = parseInt(raw, 10);
	return Number.isNaN(n) ? fallback : n;
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
				const w = intAttr(el, 'width', 1);
				nodes.push({
					type: 'size',
					width: w,
					height: intAttr(el, 'height', w),
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
					style: enumAttr(el, 'style', ['single', 'double'] as const, 'single'),
				});
				break;
			case 'barcode':
				nodes.push({
					type: 'barcode',
					barcodeType: el.getAttribute('type') ?? 'code128',
					height: intAttr(el, 'height', 40),
					value: (el.textContent ?? '').trim(),
				});
				break;
			case 'qrcode':
				nodes.push({
					type: 'qrcode',
					size: intAttr(el, 'size', 4),
					value: (el.textContent ?? '').trim(),
				});
				break;
			case 'image':
				nodes.push({
					type: 'image',
					src: el.getAttribute('src') ?? '',
					width: intAttr(el, 'width', 200),
				});
				break;
			case 'cut':
				nodes.push({
					type: 'cut',
					cutType: enumAttr(el, 'type', ['full', 'partial'] as const, 'partial'),
				});
				break;
			case 'feed':
				nodes.push({ type: 'feed', lines: intAttr(el, 'lines', 1) });
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
			cols.push({
				type: 'col',
				width: intAttr(child, 'width', 12),
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
		paperWidth: intAttr(root, 'paper-width', 48),
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

function renderNodes(nodes: ThermalNode[]): string {
	return nodes.map(renderNode).join('');
}

function renderNode(node: ThermalNode): string {
	switch (node.type) {
		case 'raw-text':
			return escapeHtml(node.value);
		case 'text':
			return `<div>${renderNodes(node.children)}</div>`;
		case 'bold':
			return `<strong>${renderNodes(node.children)}</strong>`;
		case 'underline':
			return `<span style="text-decoration: underline">${renderNodes(node.children)}</span>`;
		case 'invert':
			return `<span style="background: #000; color: #fff; padding: 0 4px">${renderNodes(node.children)}</span>`;
		case 'size':
			return `<span style="transform: scale(${node.width}, ${node.height}); transform-origin: left top; display: inline-block; line-height: 1.2">${renderNodes(node.children)}</span>`;
		case 'align':
			return `<div style="text-align: ${node.mode}">${renderNodes(node.children)}</div>`;
		case 'row': {
			const cols = node.children.map(renderCol).join('');
			return `<div style="display: flex">${cols}</div>`;
		}
		case 'col':
			return renderCol(node);
		case 'line':
			if (node.style === 'double') {
				return '<hr style="border: none; border-top: 3px double #000; margin: 4px 0" />';
			}
			return '<hr style="border: none; border-top: 1px dashed #000; margin: 4px 0" />';
		case 'barcode':
			return generateBarcodeSvg(node.value, { type: (node.barcodeType as 'qr' | 'code128' | 'ean13' | 'code39') ?? 'code128', height: node.height / 4 });
		case 'qrcode':
			return generateBarcodeSvg(node.value, { type: 'qr', scale: node.size });
		case 'image':
			return `<div style="text-align: center; padding: 8px 0"><img src="${escapeHtml(node.src)}" style="max-width: ${node.width}px; height: auto" /></div>`;
		case 'cut':
			return '<div style="border-top: 1px dashed #ccc; margin: 12px 0; position: relative"><span style="position: absolute; top: -8px; left: -4px; font-size: 14px">&#9986;</span></div>';
		case 'feed':
			return `<div style="height: ${node.lines * 1.4}em"></div>`;
		case 'drawer':
			return '';
		case 'receipt':
			return renderNodes(node.children);
		default:
			return '';
	}
}

function renderCol(node: ColNode): string {
	return `<span style="flex: 0 0 ${node.width}ch; text-align: ${node.align}; overflow: hidden">${renderNodes(node.children)}</span>`;
}

function renderHtml(ast: ReceiptNode): string {
	const width = ast.paperWidth;
	const inner = renderNodes(ast.children);
	return `<div style="width: ${width}ch; font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.4; background: #fff; color: #000; padding: 16px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); margin: 0 auto; overflow: hidden; white-space: pre-wrap; word-break: break-all;">${inner}</div>`;
}

// -- Public API --

export function renderThermalPreview(
	template: string,
	data: Record<string, unknown>,
): string {
	const resolved = Mustache.render(template, data);
	const ast = parseXml(resolved);
	return renderHtml(ast);
}
