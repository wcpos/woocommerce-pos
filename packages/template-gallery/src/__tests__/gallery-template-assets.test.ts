import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const repoRoot = path.resolve(__dirname, '../../../../');
const galleryDir = path.join(repoRoot, 'templates', 'gallery');

describe('gallery template assets', () => {
	it('includes a browser thermal-style receipt template', () => {
		const jsonPath = path.join(galleryDir, 'thermal-style-receipt.json');
		const htmlPath = path.join(galleryDir, 'thermal-style-receipt.html');

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

		expect(metadata.key).toBe('thermal-style-receipt');
		expect(metadata.engine).toBe('logicless');
		expect(metadata.type).toBe('receipt');
		expect(metadata.category).toBe('receipt');
		expect(metadata.output_type).toBe('html');
		expect(metadata.version).toBe(1);
		expect(html).toContain('font-family: monospace');
		expect(html).toContain('{{store.name}}');
	});

	it('uses bold product names in bundled thermal templates', () => {
		const thermalFiles = [
			'thermal-simple-80mm.xml',
			'thermal-simple-58mm.xml',
			'thermal-detailed-80mm.xml',
		];

		for (const filename of thermalFiles) {
			const content = fs.readFileSync(path.join(galleryDir, filename), 'utf8');
			expect(content).toContain('<bold>{{name}}</bold>');
		}
	});
});
