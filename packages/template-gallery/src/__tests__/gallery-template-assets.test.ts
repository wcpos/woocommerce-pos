import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const repoRoot = path.resolve(__dirname, '../../../../');
const galleryDir = path.join(repoRoot, 'templates', 'gallery');
const previewDir = path.join(repoRoot, 'assets', 'img', 'template-gallery', 'previews');

function getBundledGalleryKeys(): string[] {
	const keys = new Set<string>();
	for (const filename of fs.readdirSync(galleryDir)) {
		const parsed = path.parse(filename);
		if (['.html', '.xml', '.php'].includes(parsed.ext)) {
			keys.add(parsed.name);
		}
	}
	return Array.from(keys).sort();
}

// Templates whose preview PNG dimensions must match the 58mm paper width.
const THERMAL_58MM_KEYS = new Set([
	'thermal-simple-58mm',
	'thermal-detailed-58mm',
]);

// All thermal templates (need a preview PNG sized to 58mm or 80mm).
const THERMAL_KEYS = new Set(
	getBundledGalleryKeys().filter((key) => key.startsWith('thermal-'))
);

function findContentFile(key: string): string | null {
	for (const ext of ['html', 'xml', 'php']) {
		const candidate = path.join(galleryDir, `${key}.${ext}`);
		if (fs.existsSync(candidate)) {
			return candidate;
		}
	}
	return null;
}

function readPngDimensions(filePath: string): { width: number; height: number } {
	const buffer = fs.readFileSync(filePath);
	return {
		width: buffer.readUInt32BE(16),
		height: buffer.readUInt32BE(20),
	};
}

function removeComments(root: Document): void {
	const walker = root.createTreeWalker(root, NodeFilter.SHOW_COMMENT);
	const comments: Comment[] = [];
	let node = walker.nextNode();
	while (node) {
		comments.push(node as Comment);
		node = walker.nextNode();
	}
	comments.forEach((comment) => comment.remove());
}

beforeEach(() => {
	(window as Window & { wcpos?: { templateGallery?: { previewBaseUrl?: string } } }).wcpos = {
		templateGallery: {
			previewBaseUrl: 'https://example.test/wp-content/plugins/woocommerce-pos/assets/img/template-gallery/previews',
		},
	};
});

afterEach(() => {
	delete (window as Window & { wcpos?: unknown }).wcpos;
});

describe('gallery template assets', () => {
	it('includes a narrow browser receipt template that is webview-portable and B&W', () => {
		const htmlPath = path.join(galleryDir, 'narrow-receipt.html');

		expect(fs.existsSync(htmlPath)).toBe(true);

		const html = fs.readFileSync(htmlPath, 'utf8');

		expect(html).toContain('monospace');
		expect(html).toContain('{{store.name}}');
		// Older embedded WebViews render flex unreliably; rely on tables instead.
		expect(html).not.toContain('display: flex');
		// Receipts must print cleanly in B&W — no grey hierarchy.
		expect(html).not.toMatch(/color:\s*#(?:444|555|888)/);
	});

	it('uses bold product names in bundled thermal templates', () => {
		const thermalFiles = [
			'thermal-simple-80mm.xml',
			'thermal-simple-58mm.xml',
			'thermal-detailed-80mm.xml',
		];

		for (const filename of thermalFiles) {
			const content = fs.readFileSync(path.join(galleryDir, filename), 'utf8');
			const xml = new DOMParser().parseFromString(content, 'text/xml');
			const boldProductName = Array.from(xml.querySelectorAll('bold')).some(
				(element) => element.textContent?.trim() === '{{name}}'
			);

			expect(boldProductName, filename).toBe(true);
		}
	});

	it('uses a literal code128 type for order barcodes', () => {
		const barcodeTemplates = [
			'detailed-receipt.html',
			'thermal-simple-80mm.xml',
			'thermal-detailed-80mm.xml',
		];

		for (const filename of barcodeTemplates) {
			const content = fs.readFileSync(path.join(galleryDir, filename), 'utf8');
			expect(content, filename).toContain('type="code128"');
			expect(content, filename).not.toContain('{{presentation_hints.order_barcode_type}}');
		}
	});

	it('keeps 80mm thermal rows flexible across 42 and 48 CPL printers', () => {
		const thermalFiles = [
			'thermal-simple-80mm.xml',
			'thermal-kitchen-ticket.xml',
			'thermal-detailed-80mm.xml',
		];

		for (const filename of thermalFiles) {
			const content = fs.readFileSync(path.join(galleryDir, filename), 'utf8');

			expect(content, filename).toContain('width="*"');
		}
	});


	it('marks invoice bank-transfer details as fake editable placeholders', () => {
		const content = fs.readFileSync(path.join(galleryDir, 'invoice.html'), 'utf8');

		expect(content).toContain('IMPORTANT: Sample bank details below are fake placeholders');
		expect(content).toContain('FAKE SAMPLE IBAN');
		expect(content).toContain('FAKE SAMPLE BIC');
		expect(content).not.toContain('GB29 NWBK 6016 1331 9268 19');
		expect(content).not.toContain('NWBKGB2L');
	});

	it('does not ship hardcoded tax-invoice retention boilerplate', () => {
		const taxInvoiceTemplates = ['detailed-receipt.html'];

		for (const filename of taxInvoiceTemplates) {
			const content = fs.readFileSync(path.join(galleryDir, filename), 'utf8');
			expect(content, filename).not.toContain('tax_invoice_retain');
			expect(content, filename).not.toContain('Please retain for your records');
		}
	});


	it('does not expose removed gallery templates or legacy PHP examples', async () => {
		const { getGalleryPreviewSrc } = await import('../preview-assets');
		const removedGalleryFiles = [
			'branded-receipt.html',
			'branded-receipt.json',
			'return-receipt.html',
			'return-receipt.json',
			'tax-invoice.html',
			'tax-invoice.json',
			'gift-receipt.php',
			'minimal-receipt.php',
			'thermal-receipt.php',
		];

		for (const removed of removedGalleryFiles) {
			expect(fs.existsSync(path.join(galleryDir, removed)), removed).toBe(false);
		}

		for (const removedKey of ['branded-receipt', 'return-receipt', 'tax-invoice']) {
			expect(fs.existsSync(path.join(previewDir, `${removedKey}.png`)), removedKey).toBe(false);
			expect(getGalleryPreviewSrc(removedKey), removedKey).toBeUndefined();
		}
	});

	it('maps every bundled gallery template to a content file', () => {
		for (const key of getBundledGalleryKeys()) {
			expect(findContentFile(key), key).not.toBeNull();
		}
	});

	it('maps every bundled gallery template to a committed preview image', async () => {
		const { getGalleryPreviewSrc } = await import('../preview-assets');

		for (const key of getBundledGalleryKeys()) {
			expect(fs.existsSync(path.join(previewDir, `${key}.png`)), key).toBe(true);
			expect(getGalleryPreviewSrc(key), key).toBe(
				`https://example.test/wp-content/plugins/woocommerce-pos/assets/img/template-gallery/previews/${key}.png`,
			);
		}
	});


	it('uses natural receipt paper widths for thermal preview images', () => {
		for (const key of THERMAL_KEYS) {
			const expectedWidth = THERMAL_58MM_KEYS.has(key) ? 274 : 398;
			const dimensions = readPngDimensions(path.join(previewDir, `${key}.png`));
			expect(dimensions.width, key).toBe(expectedWidth);
		}
	});

	it('ships the RTL HTML template with logical properties and direction=rtl', () => {
		const htmlPath = path.join(galleryDir, 'standard-receipt-rtl.html');

		expect(fs.existsSync(htmlPath)).toBe(true);

		const html = fs.readFileSync(htmlPath, 'utf8');

		// Outer wrapper carries dir + unicode-bidi for mixed-script safety.
		expect(html).toContain('dir="rtl"');
		expect(html).toContain('unicode-bidi: plaintext');

		// Uses logical text-align values; physical ones would break under either direction.
		expect(html).toContain('text-align: start');
		expect(html).toContain('text-align: end');

		// Ignore comments that document the convention before checking actual markup.
		const parsedHtml = new DOMParser().parseFromString(html, 'text/html');
		removeComments(parsedHtml);
		const body = parsedHtml.body.innerHTML;
		expect(body).not.toContain('text-align: left');
		expect(body).not.toContain('text-align: right');
		expect(body).not.toContain('margin-left: auto');
		expect(body).toContain('margin-inline-start: auto');
	});

	it('ships the RTL thermal template with mirrored columns and codepage caveat', () => {
		const xmlPath = path.join(galleryDir, 'thermal-simple-80mm-rtl.xml');

		expect(fs.existsSync(xmlPath)).toBe(true);

		const xml = fs.readFileSync(xmlPath, 'utf8');

		expect(xml).toContain('Phone: {{store.phone}}');
		expect(xml).toContain('Email: {{store.email}}');

		const parsed = new DOMParser().parseFromString(xml, 'text/xml');
		removeComments(parsed);
		expect(parsed.getElementsByTagName('parsererror').length).toBe(0);

		// Preserves the 42/48 CPL flexibility convention.
		expect(xml).toContain('width="*"');
		// Product name still bold (same invariant as the LTR thermal templates).
		const boldProductName = Array.from(parsed.querySelectorAll('bold')).some(
			(element) => element.textContent?.trim() === '{{name}}'
		);
		expect(boldProductName).toBe(true);

		// Mirrored layout: label column (the * width column) is right-aligned,
		// amount column (the fixed-width column) is implicitly left-aligned.
		const rows = parsed.querySelectorAll('row');
		expect(rows.length).toBeGreaterThan(0);
		let mirroredRowCount = 0;
		for (const row of Array.from(rows)) {
			const cols = row.querySelectorAll('col');
			if (cols.length !== 2) {
				continue;
			}
			const first = cols[0];
			const second = cols[1];
			const firstWidth = first.getAttribute('width');
			// Mirrored shape: fixed-width amount column on the left (no align attr
			// or align="left"), star-width label column on the right with align="right".
			if (
				firstWidth !== null &&
				/^\d+$/.test(firstWidth) &&
				second.getAttribute('width') === '*' &&
				second.getAttribute('align') === 'right'
			) {
				mirroredRowCount++;
			}
		}
		expect(mirroredRowCount).toBeGreaterThan(0);
	});

});
