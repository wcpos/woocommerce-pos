import * as React from 'react';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it } from 'vitest';

import { PreviewViewport, type PreviewPaperWidth } from '@wcpos/ui';

const mountedRoots: Root[] = [];

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	mountedRoots.length = 0;
	document.body.innerHTML = '';
});

function renderPreviewViewport(paperWidth: PreviewPaperWidth = 'a4') {
	const container = document.createElement('div');
	const root = createRoot(container);
	mountedRoots.push(root);
	document.body.appendChild(container);

	act(() => {
		root.render(
			<PreviewViewport
				paperWidth={paperWidth}
				zoomInLabel="Zoom in"
				zoomOutLabel="Zoom out"
			>
				<iframe title="Preview iframe" />
			</PreviewViewport>,
		);
	});

	return container;
}

describe('PreviewViewport', () => {
	it('renders zoom controls with a − / value / + layout', () => {
		const container = renderPreviewViewport('a4');

		const buttons = Array.from(container.querySelectorAll('button[aria-label]'));
		const labels = buttons.map((b) => b.getAttribute('aria-label'));
		expect(labels).toEqual(['Zoom out', 'Zoom in']);

		const value = container.querySelector('[data-testid="preview-viewport-zoom-value"]');
		expect(value?.textContent).toMatch(/^\d+%$/);
	});

	it('uses paper-sized canvas with a scaled frame', () => {
		const container = renderPreviewViewport('a4');
		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]') as HTMLElement;
		const frame = container.querySelector('[data-testid="preview-viewport-canvas-frame"]') as HTMLElement;

		expect(canvas.style.width).toBe('794px');
		expect(canvas.style.height).toBe('1123px');
		expect(canvas.style.transformOrigin).toBe('top left');

		// In jsdom the container has zero size, so auto-fit yields 100% and the
		// frame dimensions equal the paper.
		expect(canvas.style.transform).toBe('scale(1)');
		expect(frame.style.width).toBe('794px');
		expect(frame.style.height).toBe('1123px');
	});

	it('keeps zoom controls outside the scrollable canvas area', () => {
		const container = renderPreviewViewport('a4');
		const controls = container.querySelector(
			'[data-testid="preview-viewport-zoom-controls"]',
		) as HTMLElement;
		const scrollArea = container.querySelector(
			'[data-testid="preview-viewport-scroll-area"]',
		) as HTMLElement;
		const frame = container.querySelector('[data-testid="preview-viewport-canvas-frame"]') as HTMLElement;

		expect(scrollArea.contains(frame)).toBe(true);
		expect(scrollArea.contains(controls)).toBe(false);
		expect(controls.parentElement).not.toBe(scrollArea);
	});

	it('does not label the zoom control wrapper as one of its child actions', () => {
		const container = renderPreviewViewport('a4');
		const controls = container.querySelector(
			'[data-testid="preview-viewport-zoom-controls"]',
		) as HTMLElement;

		expect(controls.getAttribute('aria-label')).toBeNull();
		expect(controls.getAttribute('role')).toBeNull();
		expect(container.querySelector('button[aria-label="Zoom out"]')).toBeTruthy();
		expect(container.querySelector('[data-testid="preview-viewport-zoom-value"]')?.getAttribute('aria-label')).toBe(
			'Zoom 100%',
		);
		expect(container.querySelector('button[aria-label="Zoom in"]')).toBeTruthy();
	});

	it('uses 58mm paper dimensions when configured', () => {
		const container = renderPreviewViewport('58mm');
		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]') as HTMLElement;

		expect(canvas.style.width).toBe('219px');
		expect(canvas.style.height).toBe('520px');
	});

	it('sizes the canvas to the measured iframe content once it loads', () => {
		const container = renderPreviewViewport('80mm');
		const iframe = container.querySelector('iframe') as HTMLIFrameElement;
		const doc = iframe.contentDocument;
		if (!doc?.body) {
			throw new Error('expected the iframe to expose a same-origin document');
		}

		Object.defineProperty(doc.body, 'scrollWidth', { configurable: true, value: 360 });
		Object.defineProperty(doc.body, 'scrollHeight', { configurable: true, value: 940 });

		act(() => {
			iframe.dispatchEvent(new Event('load'));
		});

		const canvas = container.querySelector(
			'[data-testid="preview-viewport-canvas"]',
		) as HTMLElement;
		expect(canvas.style.width).toBe('360px');
		expect(canvas.style.height).toBe('940px');
	});

	it('zooms through 10% steps when + and − are clicked', () => {
		const container = renderPreviewViewport('a4');
		const zoomOut = container.querySelector('button[aria-label="Zoom out"]') as HTMLButtonElement;
		const zoomIn = container.querySelector('button[aria-label="Zoom in"]') as HTMLButtonElement;
		const value = container.querySelector('[data-testid="preview-viewport-zoom-value"]') as HTMLElement;
		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]') as HTMLElement;

		expect(value.textContent).toBe('100%');

		act(() => {
			zoomOut.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		});
		expect(value.textContent).toBe('90%');
		expect(value.getAttribute('aria-label')).toBe('Zoom 90%');
		expect(canvas.style.transform).toBe('scale(0.9)');

		act(() => {
			zoomIn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		});
		act(() => {
			zoomIn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		});
		expect(value.textContent).toBe('110%');
	});

	it('disables − at the minimum step and + at the maximum step', () => {
		const container = renderPreviewViewport('a4');
		const zoomOut = container.querySelector('button[aria-label="Zoom out"]') as HTMLButtonElement;
		const zoomIn = container.querySelector('button[aria-label="Zoom in"]') as HTMLButtonElement;

		// Click − until disabled
		for (let i = 0; i < 30; i++) {
			if (zoomOut.disabled) break;
			act(() => {
				zoomOut.dispatchEvent(new MouseEvent('click', { bubbles: true }));
			});
		}
		expect(zoomOut.disabled).toBe(true);
		expect(zoomIn.disabled).toBe(false);

		// Click + until disabled
		for (let i = 0; i < 30; i++) {
			if (zoomIn.disabled) break;
			act(() => {
				zoomIn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
			});
		}
		expect(zoomIn.disabled).toBe(true);
		expect(zoomOut.disabled).toBe(false);
	});
});
