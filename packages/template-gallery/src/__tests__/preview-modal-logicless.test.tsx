import * as React from 'react';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { buildPreviewModalSrcDoc, PreviewModal } from '../components/preview-modal';
import { usePreview } from '../hooks/use-preview';
import type { PreviewResponse } from '../types';

vi.mock('../hooks/use-preview', () => ({
	usePreview: vi.fn(),
}));

const usePreviewMock = vi.mocked(usePreview);
const mountedRoots: Root[] = [];

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	mountedRoots.length = 0;
	document.body.innerHTML = '';
	vi.clearAllMocks();
});

describe('PreviewModal logicless previews', () => {
	it('renders template_content with receipt_data instead of sanitized preview_html', () => {
		const preview: PreviewResponse = {
			engine: 'logicless',
			template_content: '<div style="display:flex;justify-content:space-between"><span>{{label}}</span><span>{{amount}}</span></div>',
			receipt_data: { label: 'Subtotal (sin impuestos)', amount: '15,00 $' },
			preview_html: '<div style="justify-content:space-between"><span>Subtotal (sin impuestos)</span><span>15,00 $</span></div>',
			order_id: 0,
			template_id: 'invoice',
		};

		const srcDoc = buildPreviewModalSrcDoc(preview);

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:210mm');
		expect(srcDoc).toContain('style="display:flex;justify-content:space-between"');
		expect(srcDoc).not.toContain('style="justify-content:space-between"');
		expect(srcDoc).toContain('Subtotal (sin impuestos)');
		expect(srcDoc).toContain('15,00 $');
	});

	it('preserves the legacy translation helper for client-rendered logicless previews', () => {
		const preview: PreviewResponse = {
			engine: 'logicless',
			template_content: '<p>{{#t}}Translated label{{/t}}</p>',
			receipt_data: {},
			preview_html: '<p>Translated label</p>',
			order_id: 0,
			template_id: 'invoice',
		};

		const srcDoc = buildPreviewModalSrcDoc(preview);

		expect(srcDoc).toContain('<p>Translated label</p>');
		expect(srcDoc).not.toContain('{{#t}}');
	});

	it('wraps preview_html fallback when source template data is missing', () => {
		const preview: PreviewResponse = {
			engine: 'logicless',
			preview_html: '<main>Fallback preview</main>',
			order_id: 0,
			template_id: 'invoice',
		};

		const srcDoc = buildPreviewModalSrcDoc(preview);

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:210mm');
		expect(srcDoc).toContain('<main>Fallback preview</main>');
	});

	it('keeps full HTML preview_html fallback untouched', () => {
		const fullHtml = '<!DOCTYPE html><html><body><main>Full HTML</main></body></html>';
		const preview: PreviewResponse = {
			engine: 'logicless',
			preview_html: fullHtml,
			order_id: 0,
			template_id: 'invoice',
		};

		expect(buildPreviewModalSrcDoc(preview)).toBe(fullHtml);
	});

	it('wraps legacy partial preview_html in the modal iframe fallback', async () => {
		usePreviewMock.mockReturnValue({
			data: {
				engine: 'legacy-php',
				preview_html: '<main>Legacy fallback</main>',
				order_id: 0,
				template_id: 'legacy',
			},
			isLoading: false,
			isFetching: false,
			isError: false,
		} as ReturnType<typeof usePreview>);

		const container = document.createElement('div');
		const root = createRoot(container);
		mountedRoots.push(root);
		document.body.appendChild(container);

		await act(async () => {
			root.render(
				<PreviewModal
					templateId="legacy"
					templateName="Legacy"
					isGallery
					onClose={() => {}}
				/>,
			);
		});

		const iframe = container.querySelector('iframe');
		expect(iframe?.getAttribute('srcdoc')).toContain('wcpos-preview-paper');
		expect(iframe?.getAttribute('srcdoc')).toContain('<main>Legacy fallback</main>');

		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]') as HTMLElement | null;
		expect(canvas).toBeTruthy();
		expect(canvas?.style.width).toBe('794px');
		expect(canvas?.style.height).toBe('1123px');
		expect(iframe?.getAttribute('srcdoc')).toContain('width:210mm');
	});

	it('renders an empty logicless template when receipt data is present', async () => {
		usePreviewMock.mockReturnValue({
			data: {
				engine: 'logicless',
				template_content: '',
				receipt_data: { label: 'Unused' },
				order_id: 0,
				template_id: 'empty',
			},
			isLoading: false,
			isFetching: false,
			isError: false,
		} as ReturnType<typeof usePreview>);

		const container = document.createElement('div');
		const root = createRoot(container);
		mountedRoots.push(root);
		document.body.appendChild(container);

		await act(async () => {
			root.render(
				<PreviewModal
					templateId="empty"
					templateName="Empty"
					isGallery
					onClose={() => {}}
				/>,
			);
		});

		const iframe = container.querySelector('iframe');
		expect(iframe?.getAttribute('srcdoc')).toContain('wcpos-preview-paper');
	});
});
