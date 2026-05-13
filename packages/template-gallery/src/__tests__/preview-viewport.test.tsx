import * as React from 'react';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it } from 'vitest';

import { PreviewViewport } from '@wcpos/ui';

const mountedRoots: Root[] = [];

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	mountedRoots.length = 0;
	document.body.innerHTML = '';
});

function renderPreviewViewport(defaultZoom: 50 | 75 | 100 = 50) {
	const container = document.createElement('div');
	const root = createRoot(container);
	mountedRoots.push(root);
	document.body.appendChild(container);

	act(() => {
		root.render(
			<PreviewViewport defaultZoom={defaultZoom} zoomLabel="Template zoom">
				<iframe title="Preview iframe" />
			</PreviewViewport>,
		);
	});

	return container;
}

describe('PreviewViewport', () => {
	it('renders children inside a scaled canvas using the default zoom', () => {
		const container = renderPreviewViewport(50);

		expect(container.querySelector('iframe')?.getAttribute('title')).toBe('Preview iframe');
		expect(container.querySelector('button[aria-pressed="true"]')?.textContent).toBe('50%');

		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]');
		expect(canvas?.getAttribute('style')).toContain('width: 200%');
		expect(canvas?.getAttribute('style')).toContain('transform: scale(0.5)');
		expect(canvas?.getAttribute('style')).toContain('transform-origin: top center');
	});

	it('lets users switch zoom levels', () => {
		const container = renderPreviewViewport(50);
		const zoom100 = Array.from(container.querySelectorAll('button')).find(
			(button) => button.textContent === '100%',
		);

		act(() => {
			zoom100?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		});

		expect(zoom100?.getAttribute('aria-pressed')).toBe('true');
		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]');
		expect(canvas?.getAttribute('style')).toContain('width: 100%');
		expect(canvas?.getAttribute('style')).toContain('transform: scale(1)');
	});
});
