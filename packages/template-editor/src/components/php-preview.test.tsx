import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import apiFetch from '@wordpress/api-fetch';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
	PhpPreview,
	getPhpPreviewFrame,
	getPhpPreviewIframeStyle,
	getPhpPreviewBodyClassName,
	getPhpPreviewRequestUrl,
} from './php-preview';

vi.mock('@wordpress/api-fetch', () => ({
	default: vi.fn(),
}));

vi.mock('../translations', () => ({
	t: (key: string) => {
		const strings: Record<string, string> = {
			'editor.preview': 'Preview',
			'editor.php_save_notice': 'This preview shows your last saved version with your latest POS order — save the template to refresh it.',
			'editor.template_preview': 'Template preview',
			'editor.zoom_in': 'Zoom in',
			'editor.zoom_out': 'Zoom out',
			'editor.loading_data': 'Loading…',
			'editor.preview_failed': 'Preview failed. Save the template and try again.',
			'editor.no_orders': 'No POS orders found. Create an order in the POS to preview templates.',
		};
		return strings[key] ?? key;
	},
}));

const apiFetchMock = vi.mocked(apiFetch);
const mountedRoots: Root[] = [];

beforeEach(() => {
	apiFetchMock.mockReset();
});

afterEach(() => {
	for (const root of mountedRoots) {
		root.unmount();
	}
	mountedRoots.length = 0;
	document.body.innerHTML = '';
});

describe('PhpPreview', () => {
	it('requests the latest order and adds wcpos=1 when fetching the REST preview URL', () => {
		expect(getPhpPreviewRequestUrl('https://example.test/wp-json/wcpos/v1/templates/123/preview')).toBe(
			'https://example.test/wp-json/wcpos/v1/templates/123/preview?order_id=latest&wcpos=1',
		);
		expect(getPhpPreviewRequestUrl('https://example.test/wp-json/wcpos/v1/templates/123/preview?order_id=latest')).toBe(
			'https://example.test/wp-json/wcpos/v1/templates/123/preview?order_id=latest&wcpos=1',
		);
		expect(getPhpPreviewRequestUrl('https://example.test/wp-json/wcpos/v1/templates/123/preview?wcpos=1')).toBe(
			'https://example.test/wp-json/wcpos/v1/templates/123/preview?wcpos=1&order_id=latest',
		);
	});

	it('renders the preview header and save notice without a Save button', () => {
		const markup = renderToStaticMarkup(
			<PhpPreview previewUrl="https://example.test/wp-json/wcpos/v1/templates/123/preview" />,
		);

		expect(markup).toContain('Preview');
		expect(markup).toContain('save the template to refresh it');
		expect(markup).not.toContain('<button');
	});

	it('wraps partial preview_html in the shared A4 frame', () => {
		const frame = getPhpPreviewFrame({ preview_html: '<p>Preview HTML</p>' });

		expect(frame.src).toBeNull();
		expect(frame.srcDoc).toContain('wcpos-preview-paper');
		expect(frame.srcDoc).toContain('width:210mm');
		expect(frame.srcDoc).toContain('<p>Preview HTML</p>');
	});

	it('does not wrap full HTML documents returned by PHP previews', () => {
		const html = '<!DOCTYPE html><html><body><main>Full document</main></body></html>';
		const frame = getPhpPreviewFrame({ preview_html: html });

		expect(frame.srcDoc).toBe(html);
	});

	it('uses preview_url as iframe src when no preview_html exists', () => {
		expect(getPhpPreviewFrame({ preview_url: 'https://example.test/preview' })).toEqual({
			src: 'https://example.test/preview',
			srcDoc: null,
		});
	});

	it('fills the preview viewport canvas with no fixed height', () => {
		const style = getPhpPreviewIframeStyle();

		expect(style.display).toBe('block');
		expect(style.width).toBe('100%');
		expect(style.height).toBe('100%');
		expect(style.maxWidth).toBeUndefined();
		expect(style.minHeight).toBeUndefined();
	});

	it('uses a flush preview body without p-4 padding', () => {
		expect(getPhpPreviewBodyClassName()).not.toContain('p-4');
		expect(getPhpPreviewBodyClassName()).toContain('p-0');
	});

	it('does not render an open-in-tab link after preview URL loads', async () => {
		apiFetchMock.mockResolvedValueOnce({ preview_url: 'https://example.test/preview-output' });
		const container = document.createElement('div');
		const root = createRoot(container);
		mountedRoots.push(root);
		document.body.appendChild(container);

		await act(async () => {
			root.render(
				<PhpPreview previewUrl="https://example.test/wp-json/wcpos/v1/templates/123/preview" />,
			);
		});

		await act(async () => {
			await Promise.resolve();
		});

		expect(container.textContent).not.toContain('Open in tab');
		expect(container.querySelector('a[href="https://example.test/preview-output"]')).toBeNull();
		expect(container.querySelector('iframe')?.getAttribute('src')).toBe('https://example.test/preview-output');

		const canvas = container.querySelector('[data-testid="preview-viewport-canvas"]') as HTMLElement | null;
		expect(canvas).toBeTruthy();
		expect(canvas?.style.width).toBe('794px');
		expect(canvas?.style.height).toBe('1123px');
	});

	it('renders preview failure fallback when the REST preview request fails', async () => {
		apiFetchMock.mockRejectedValueOnce(new Error('No route'));
		const container = document.createElement('div');
		const root = createRoot(container);
		mountedRoots.push(root);
		document.body.appendChild(container);

		await act(async () => {
			root.render(
				<PhpPreview previewUrl="https://example.test/wp-json/wcpos/v1/templates/123/preview" />,
			);
		});

		await act(async () => {
			await Promise.resolve();
		});

		expect(apiFetchMock).toHaveBeenCalledWith({
			url: 'https://example.test/wp-json/wcpos/v1/templates/123/preview?order_id=latest&wcpos=1',
			method: 'GET',
		});
		expect(container.innerHTML).toContain('Preview failed. Save the template and try again.');
	});

	it('shows the create-an-order message when the preview requires a real order', async () => {
		apiFetchMock.mockResolvedValueOnce({ engine: 'legacy-php', requires_order: true });
		const container = document.createElement('div');
		const root = createRoot(container);
		mountedRoots.push(root);
		document.body.appendChild(container);

		await act(async () => {
			root.render(
				<PhpPreview previewUrl="https://example.test/wp-json/wcpos/v1/templates/123/preview" />,
			);
		});

		await act(async () => {
			await Promise.resolve();
		});

		expect(container.textContent).toContain(
			'No POS orders found. Create an order in the POS to preview templates.',
		);
		expect(container.querySelector('iframe')).toBeNull();
	});
});
