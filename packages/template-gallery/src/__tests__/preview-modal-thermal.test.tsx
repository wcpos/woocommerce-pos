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

describe('PreviewModal thermal previews', () => {
	it('renders thermal template_content with receipt_data inside the shared 58mm frame', () => {
		const preview: PreviewResponse = {
			engine: 'thermal',
			template_content: '<receipt paper-width="32"><text>Thermal XML {{order.number}}</text></receipt>',
			receipt_data: { order: { number: '1234' } },
			paper_width: '58mm',
			order_id: 0,
			template_id: 'thermal-simple-58mm',
		};

		const srcDoc = buildPreviewModalSrcDoc(preview);

		expect(srcDoc).toContain('wcpos-preview-paper');
		expect(srcDoc).toContain('width:58mm');
		expect(srcDoc).toContain('Thermal XML 1234');
		expect(srcDoc).not.toContain('<receipt');
	});

	it('uses 58mm paper dimensions for thermal previews', async () => {
		usePreviewMock.mockReturnValue({
			data: {
				engine: 'thermal',
				template_content: '<receipt paper-width="32"><text>Thermal XML {{order.number}}</text></receipt>',
				receipt_data: { order: { number: '1234' } },
				paper_width: '58mm',
				order_id: 0,
				template_id: 'thermal-simple-58mm',
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
					templateId="thermal-simple-58mm"
					templateName="Thermal"
					isGallery
					onClose={() => {}}
				/>,
			);
		});

		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]') as HTMLElement | null;
		expect(canvas).toBeTruthy();
		expect(canvas?.style.width).toBe('219px');
		expect(canvas?.style.height).toBe('520px');
	});
});
