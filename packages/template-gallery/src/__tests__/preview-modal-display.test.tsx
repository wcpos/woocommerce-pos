import { act } from 'react';

import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { buildDisplayPreviewUrl, PreviewModal } from '../components/preview-modal';
import { usePreview } from '../hooks/use-preview';

vi.mock('../hooks/use-preview', () => ({ usePreview: vi.fn(() => ({})) }));
let root: Root;
beforeEach(() => {
	(window as any).wcpos = {
		templateGallery: {
			isProActive: true,
			displayPreviewUrl: 'https://example.test/wcpos-display/',
			previewBaseUrl: 'https://example.test/previews',
		},
	};
});
afterEach(() => {
	act(() => root?.unmount());
	document.body.innerHTML = '';
	delete (window as any).wcpos;
	vi.clearAllMocks();
});
function mount(isGallery = true, templateId: string | number = 'display-pocket') {
	const container = document.createElement('div');
	document.body.append(container);
	root = createRoot(container);
	act(() =>
		root.render(
			<PreviewModal
				templateType="display"
				templateId={templateId}
				templateName="Display"
				isGallery={isGallery}
				onClose={() => {}}
			/>
		)
	);
	return container;
}

describe('display preview', () => {
	it.each([
		[{ gallery: 'display-pocket' }, 'gallery=display-pocket'],
		[{ template: 123 }, 'template=123'],
		[{ template: 'virtual-display' }, 'template=virtual-display'],
	])('builds a state URL for %j', (target, query) => {
		expect(
			buildDisplayPreviewUrl('https://example.test/wcpos-display/?existing=1', {
				state: 'cart',
				...target,
			})
		).toBe(`https://example.test/wcpos-display/?existing=1&preview=cart&${query}`);
	});

	it('changes state and viewport without fetching a receipt preview', () => {
		const container = mount();
		const frame = () =>
			container.querySelector<HTMLIFrameElement>('[data-testid="display-preview-frame"]')!;
		const canvas = () =>
			container.querySelector<HTMLElement>('[data-testid="preview-viewport-canvas"]')!;
		expect(frame()?.getAttribute('src')).toBe(
			'https://example.test/wcpos-display/?preview=cart&gallery=display-pocket'
		);
		expect(frame().hasAttribute('srcdoc')).toBe(false);
		expect(frame().hasAttribute('sandbox')).toBe(false);
		expect(canvas().style.width).toBe('1280px');
		expect(canvas().style.height).toBe('800px');
		// The filmstrip: one live thumbnail per state, in the order a sale happens.
		const strip = container.querySelector('[data-testid="display-state-strip"]')!;
		const rows = Array.from(strip.querySelectorAll<HTMLElement>('[role="radio"]'));
		expect(rows.map((row) => row.querySelector('iframe')?.getAttribute('src'))).toEqual(
			[
				'idle',
				'cart.empty',
				'cart',
				'payment.started',
				'payment.approved',
				'payment.declined',
				'payment.complete',
			].map(
				(value) => `https://example.test/wcpos-display/?preview=${value}&gallery=display-pocket`
			)
		);
		expect(rows.map((row) => row.getAttribute('aria-checked'))).toEqual([
			'false',
			'false',
			'true',
			'false',
			'false',
			'false',
			'false',
		]);
		// Fifth row: payment.approved.
		act(() => rows[4]!.click());
		expect(rows.map((row) => row.getAttribute('tabindex'))).toEqual([
			'-1',
			'-1',
			'-1',
			'-1',
			'0',
			'-1',
			'-1',
		]);
		// Arrow keys walk the states in sale order from the strip's single tab stop.
		act(() => {
			strip.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
		});
		expect(frame().getAttribute('src')).toBe(
			'https://example.test/wcpos-display/?preview=payment.declined&gallery=display-pocket'
		);
		act(() => {
			strip.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
		});
		expect(frame().getAttribute('src')).toBe(
			'https://example.test/wcpos-display/?preview=payment.approved&gallery=display-pocket'
		);
		const phone = Array.from(container.querySelectorAll('button')).find(
			(button) => button.textContent === 'Phone'
		)!;
		act(() => phone.click());
		expect(frame().getAttribute('src')).toBe(
			'https://example.test/wcpos-display/?preview=payment.approved&gallery=display-pocket'
		);
		expect(canvas().style.width).toBe('390px');
		expect(canvas().style.height).toBe('844px');
		expect(phone.getAttribute('aria-checked')).toBe('true');
		expect(usePreview).not.toHaveBeenCalled();
	});

	it.each([123, 'virtual-display'])('previews installed template %s', (id) => {
		const container = mount(false, id);
		expect(
			container.querySelector('[data-testid="display-preview-frame"]')?.getAttribute('src')
		).toBe(`https://example.test/wcpos-display/?preview=cart&template=${id}`);
		expect(usePreview).not.toHaveBeenCalled();
	});

	it.each([
		[true, 'display-pocket'],
		[false, 123],
		[true, 'missing-image'],
	] as const)(
		'without Pro uses a gallery image or explains the requirement (%s, %s)',
		(isGallery, id) => {
			(window as any).wcpos.templateGallery.isProActive = false;
			const container = mount(isGallery, id);
			expect(container.querySelector('iframe, select, [role="radio"]')).toBeNull();
			if (isGallery && id === 'display-pocket') {
				expect(container.querySelector('img')?.getAttribute('src')).toBe(
					'https://example.test/previews/display-pocket.webp'
				);
			} else {
				expect(container.textContent).toContain('Previewing display templates needs WCPOS Pro.');
				expect(container.querySelector('a')?.getAttribute('href')).toBe(
					'https://docs.wcpos.com/customer-display'
				);
			}
			expect(usePreview).not.toHaveBeenCalled();
		}
	);
});
