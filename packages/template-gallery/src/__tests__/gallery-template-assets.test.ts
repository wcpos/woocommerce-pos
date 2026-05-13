import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const repoRoot = path.resolve(__dirname, '../../../../');
const galleryDir = path.join(repoRoot, 'templates', 'gallery');
const previewDir = path.join(repoRoot, 'assets', 'img', 'template-gallery', 'previews');


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
		const jsonPath = path.join(galleryDir, 'narrow-receipt.json');
		const htmlPath = path.join(galleryDir, 'narrow-receipt.html');

		expect(fs.existsSync(jsonPath)).toBe(true);
		expect(fs.existsSync(htmlPath)).toBe(true);

		const metadata = JSON.parse(fs.readFileSync(jsonPath, 'utf8')) as {
			key: string;
			engine: string;
			type: string;
			category: string;
			output_type: string;
			version: number;
		};
		const html = fs.readFileSync(htmlPath, 'utf8');

		expect(metadata.key).toBe('narrow-receipt');
		expect(metadata.engine).toBe('logicless');
		expect(metadata.type).toBe('receipt');
		expect(metadata.category).toBe('receipt');
		expect(metadata.output_type).toBe('html');
		expect(metadata.version).toBe(1);
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

	it('maps every bundled gallery template to a committed preview image', async () => {
		const { getGalleryPreviewSrc } = await import('../preview-assets');
		const metadataFiles = fs.readdirSync(galleryDir).filter((filename: string) => filename.endsWith('.json'));

		for (const filename of metadataFiles) {
			const metadata = JSON.parse(fs.readFileSync(path.join(galleryDir, filename), 'utf8')) as {
				key: string;
			};

			expect(fs.existsSync(path.join(previewDir, `${metadata.key}.png`)), metadata.key).toBe(true);
			expect(getGalleryPreviewSrc(metadata.key), metadata.key).toBe(`https://example.test/wp-content/plugins/woocommerce-pos/assets/img/template-gallery/previews/${metadata.key}.png`);
		}
	});

	it('ships the RTL HTML template with logical properties and direction=rtl', () => {
		const jsonPath = path.join(galleryDir, 'standard-receipt-rtl.json');
		const htmlPath = path.join(galleryDir, 'standard-receipt-rtl.html');

		expect(fs.existsSync(jsonPath)).toBe(true);
		expect(fs.existsSync(htmlPath)).toBe(true);

		const metadata = JSON.parse(fs.readFileSync(jsonPath, 'utf8')) as {
			key: string;
			direction: string;
			engine: string;
			output_type: string;
		};
		const html = fs.readFileSync(htmlPath, 'utf8');

		expect(metadata.key).toBe('standard-receipt-rtl');
		expect(metadata.direction).toBe('rtl');
		expect(metadata.engine).toBe('logicless');
		expect(metadata.output_type).toBe('html');

		// Outer wrapper carries dir + unicode-bidi for mixed-script safety.
		expect(html).toContain('dir="rtl"');
		expect(html).toContain('unicode-bidi: plaintext');

		// Uses logical text-align values; physical ones would break under either direction.
		expect(html).toContain('text-align: start');
		expect(html).toContain('text-align: end');

		// Strip the comment block (which mentions the convention) before grepping for
		// physical-property regressions in the actual markup.
		const body = html.replace(/<!--[\s\S]*?-->/g, '');
		expect(body).not.toContain('text-align: left');
		expect(body).not.toContain('text-align: right');
		expect(body).not.toContain('margin-left: auto');
		expect(body).toContain('margin-inline-start: auto');
	});

	it('ships the RTL thermal template with mirrored columns and codepage caveat', () => {
		const jsonPath = path.join(galleryDir, 'thermal-simple-80mm-rtl.json');
		const xmlPath = path.join(galleryDir, 'thermal-simple-80mm-rtl.xml');

		expect(fs.existsSync(jsonPath)).toBe(true);
		expect(fs.existsSync(xmlPath)).toBe(true);

		const metadata = JSON.parse(fs.readFileSync(jsonPath, 'utf8')) as {
			key: string;
			direction: string;
			engine: string;
			output_type: string;
			paper_width: string;
			description: string;
		};
		const xml = fs.readFileSync(xmlPath, 'utf8');

		expect(metadata.key).toBe('thermal-simple-80mm-rtl');
		expect(metadata.direction).toBe('rtl');
		expect(metadata.engine).toBe('thermal');
		expect(metadata.output_type).toBe('escpos');
		expect(metadata.paper_width).toBe('80mm');
		// Description must mention the printer codepage requirement so users
		// aren't surprised when buying a printer for an Arabic store.
		expect(metadata.description).toMatch(/CP864|Windows-1256/);

		// Strip XML comments before parsing — the renderer does the same.
		const stripped = xml.replace(/<!--[\s\S]*?-->/g, '');
		const parsed = new DOMParser().parseFromString(stripped, 'text/xml');
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
			// Mirrored shape: fixed-width amount column on the left (no align attr
			// or align="left"), star-width label column on the right with align="right".
			if (
				first.getAttribute('width') !== '*' &&
				second.getAttribute('width') === '*' &&
				second.getAttribute('align') === 'right'
			) {
				mirroredRowCount++;
			}
		}
		expect(mirroredRowCount).toBeGreaterThan(0);
	});

});
