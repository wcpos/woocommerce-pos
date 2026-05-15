#!/usr/bin/env node
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';

const __filename = fileURLToPath(import.meta.url);
const repoRoot = path.resolve(path.dirname(__filename), '..');
const payloadPath = path.resolve(process.argv[2] ?? path.join(os.tmpdir(), 'gallery-preview-payloads.json'));
const outputDir = path.resolve(process.argv[3] ?? path.join(repoRoot, 'assets/img/template-gallery/previews'));
const a4PreviewWidth = 794;
const screenshotScale = 2;

const viteUrl = pathToFileURL(path.join(repoRoot, 'packages/template-gallery/node_modules/vite/dist/node/index.js')).href;
const { createServer } = await import(viteUrl);

if (!fs.existsSync(payloadPath)) {
	throw new Error(`Payload file not found: ${payloadPath}`);
}

const payloads = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));
fs.mkdirSync(outputDir, { recursive: true });

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wcpos-gallery-previews-'));
const thermalUtilsPath = path.join(repoRoot, 'packages/thermal-utils/src/index.ts');
const repoRootForBrowser = repoRoot.replace(/\\/g, '/');
const thermalUtilsForBrowser = thermalUtilsPath.replace(/\\/g, '/');

fs.writeFileSync(
	path.join(tempDir, 'payloads.js'),
	`export const payloads = ${JSON.stringify(payloads)};\n`
);

fs.writeFileSync(
	path.join(tempDir, 'index.html'),
	`<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
html,body{margin:0;padding:0;background:#f3f4f6;color:#000;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
#capture{background:#fff;color:#000;overflow:hidden;}
#wcpos-preview-paper{background:#fff;color:#000;transform-origin:top left;}
img{max-width:100%;}
</style>
</head>
<body><div id="capture"><div id="wcpos-preview-paper"></div></div><script type="module" src="/src/main.js"></script></body>
</html>`
);

fs.mkdirSync(path.join(tempDir, 'src'));
fs.writeFileSync(
	path.join(tempDir, 'src/main.js'),
	`import { payloads } from '../payloads.js';
import { renderLogiclessPreview, renderThermalPreview } from '/@fs/${thermalUtilsForBrowser}';

const params = new URLSearchParams(window.location.search);
const key = params.get('key');
const payload = payloads.find((item) => item.key === key);
if (!payload) throw new Error('Unknown preview key: ' + key);

function replaceAssetUrls(value) {
	if (Array.isArray(value)) return value.map(replaceAssetUrls);
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, replaceAssetUrls(v)]));
	}
	if (typeof value === 'string' && value.includes('/assets/img/template-gallery/preview-assets/')) {
		const assetPath = value.slice(value.indexOf('/assets/img/template-gallery/preview-assets/') + 1);
		return '/@fs/${repoRootForBrowser}/' + assetPath;
	}
	return value;
}

const receiptData = replaceAssetUrls(payload.receipt_data);
const bodyHtml = payload.engine === 'thermal'
	? renderThermalPreview(payload.template_content, receiptData)
	: renderLogiclessPreview(payload.template_content, { t: true, ...receiptData });

const paper = document.getElementById('wcpos-preview-paper');
const capture = document.getElementById('capture');
const normalizedPaperWidth = payload.paper_width === '58mm' || payload.paper_width === '80mm' ? payload.paper_width : 'a4';
const nativeWidth = normalizedPaperWidth === '58mm' ? 219 : normalizedPaperWidth === '80mm' ? 302 : 794;
// Capture high-resolution source PNGs and let the gallery cards downscale them.
// A4 templates use their real CSS paper width; Playwright's device scale factor
// doubles the stored pixels so text and logos stay sharp in the browser.
const initialCaptureWidth = payload.engine === 'thermal' ? nativeWidth : ${a4PreviewWidth};
const scale = payload.engine === 'thermal' ? 1 : initialCaptureWidth / nativeWidth;
capture.style.width = initialCaptureWidth + 'px';
paper.style.width = nativeWidth + 'px';
paper.style.transform = 'scale(' + scale + ')';
paper.innerHTML = bodyHtml;

await Promise.all(Array.from(document.images).map((img) => img.complete ? Promise.resolve() : new Promise((resolve) => {
	img.addEventListener('load', resolve, { once: true });
	img.addEventListener('error', resolve, { once: true });
})));
await document.fonts.ready;
await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

if (payload.engine === 'thermal') {
	const measuredWidth = Math.ceil(Math.max(paper.scrollWidth, paper.getBoundingClientRect().width));
	capture.style.width = measuredWidth + 'px';
	paper.style.width = measuredWidth + 'px';
	await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
}

capture.style.height = Math.ceil(paper.getBoundingClientRect().height) + 'px';
window.__WCPOS_PREVIEW_READY__ = true;
`
);

const server = await createServer({
	root: tempDir,
	logLevel: 'error',
	server: {
		host: '127.0.0.1',
		fs: { allow: [repoRoot, tempDir] },
	},
});

await server.listen(0, '127.0.0.1');
const baseUrl = server.resolvedUrls?.local?.[0];
if (!baseUrl) throw new Error('Unable to start Vite preview server');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1800, height: 2400 }, deviceScaleFactor: screenshotScale });

try {
	for (const payload of payloads) {
		await page.goto(`${baseUrl}?key=${encodeURIComponent(payload.key)}`, { waitUntil: 'networkidle' });
		await page.waitForFunction(() => window.__WCPOS_PREVIEW_READY__ === true);
		const capture = page.locator('#capture');
		await capture.screenshot({ path: path.join(outputDir, `${payload.key}.png`) });
		console.log(`generated ${payload.key}.png`);
	}
} finally {
	await browser.close();
	await server.close();
	fs.rmSync(tempDir, { recursive: true, force: true });
}
