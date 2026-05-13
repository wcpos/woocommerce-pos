import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { ThermalPreview, buildThermalPreviewSrcDoc, getThermalPreviewBodyClassName, getThermalPreviewIframeStyle } from './thermal-preview';

describe('buildThermalPreviewSrcDoc', () => {
	it('uses the shared preview frame with physical paper width', () => {
		const srcDoc = buildThermalPreviewSrcDoc({
			content: '<receipt paper-width="32"><text>Test receipt</text></receipt>',
			sampleData: {},
			paperWidth: '58mm',
		});

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:58mm');
		expect(srcDoc).toContain('Test receipt');
	});

	it('infers quoted paper widths with whitespace around the attribute separator', () => {
		const srcDoc = buildThermalPreviewSrcDoc({
			content: '<receipt paper-width = "58"><text>Test receipt</text></receipt>',
			sampleData: {},
		});

		expect(srcDoc).toContain('width:58mm');
	});

	it('does not infer paper width from partial numeric prefixes', () => {
		const srcDoc = buildThermalPreviewSrcDoc({
			content: '<receipt paper-width="58wide"><text>Test receipt</text></receipt>',
			sampleData: {},
		});

		expect(srcDoc).toContain('width:210mm');
	});
});

describe('ThermalPreview layout helpers', () => {
	it('fills the preview viewport canvas with no fixed height', () => {
		const style = getThermalPreviewIframeStyle();
		expect(style.display).toBe('block');
		expect(style.width).toBe('100%');
		expect(style.height).toBe('100%');
		expect(style.minHeight).toBeUndefined();
	});

	it('uses a flush preview body without p-4 padding', () => {
		expect(getThermalPreviewBodyClassName()).not.toContain('p-4');
		expect(getThermalPreviewBodyClassName()).toContain('p-0');
	});
});

describe('ThermalPreview rendering', () => {
	it('renders 58mm thermal previews with 58mm paper dimensions', () => {
		const markup = renderToStaticMarkup(
			<ThermalPreview
				content='<receipt paper-width="32"><text>Test receipt</text></receipt>'
				sampleData={{}}
			/>,
		);

		expect(markup).toContain('data-testid="preview-viewport-canvas"');
		expect(markup).toContain('width:219px');
		expect(markup).toContain('height:520px');
	});

	it('renders 80mm thermal previews with 80mm paper dimensions', () => {
		const markup = renderToStaticMarkup(
			<ThermalPreview
				content='<receipt paper-width="80"><text>Test receipt</text></receipt>'
				sampleData={{}}
			/>,
		);

		expect(markup).toContain('width:302px');
		expect(markup).toContain('height:520px');
	});
});
