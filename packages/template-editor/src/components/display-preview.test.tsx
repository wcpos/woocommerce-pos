import { act } from 'react';

import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
	DisplayPreview,
	buildDisplayPreviewUrl,
	DISPLAY_PREVIEW_MESSAGE_TYPE,
} from './display-preview';

const props = {
	content: '<section>Draft</section>',
	templateId: 123,
	previewUrl: 'https://example.test/wcpos-display/',
	isProActive: true,
};
let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
	vi.useFakeTimers();
	container = document.createElement('div');
	document.body.appendChild(container);
	root = createRoot(container);
});

afterEach(async () => {
	await act(async () => root.unmount());
	container.remove();
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('DisplayPreview', () => {
	it.each([
		[
			'https://example.test/wcpos-display/',
			123,
			'cart',
			'https://example.test/wcpos-display/?preview=cart&template=123',
		],
		[
			'https://example.test/shop/wcpos-display/',
			42,
			'payment.started',
			'https://example.test/shop/wcpos-display/?preview=payment.started&template=42',
		],
		[
			'https://example.test/wcpos-display/?lang=fr&preview=idle&template=1',
			9,
			'cart.empty',
			'https://example.test/wcpos-display/?lang=fr&preview=cart.empty&template=9',
		],
	])('builds the preview URL for %s', (url, id, state, expected) => {
		expect(buildDisplayPreviewUrl(url, id, state)).toBe(expected);
	});

	it('defaults to cart and changes the iframe URL when selecting a state', async () => {
		await act(async () => root.render(<DisplayPreview {...props} />));
		const frame = container.querySelector('iframe')!;
		expect(frame.getAttribute('src')).toBe(
			'https://example.test/wcpos-display/?preview=cart&template=123'
		);
		expect(frame.hasAttribute('sandbox')).toBe(false);
		const select = container.querySelector('select')!;
		expect(Array.from(select.options, (option) => option.value)).toEqual([
			'idle',
			'cart.empty',
			'cart',
			'payment.started',
			'payment.approved',
			'payment.declined',
			'payment.complete',
		]);
		await act(async () => {
			select.value = 'payment.approved';
			select.dispatchEvent(new Event('change', { bubbles: true }));
		});
		expect(frame.getAttribute('src')).toBe(
			'https://example.test/wcpos-display/?preview=payment.approved&template=123'
		);
	});

	it('switches between a screen and a centered phone viewport', async () => {
		await act(async () => root.render(<DisplayPreview {...props} />));
		const frame = container.querySelector('iframe')!;
		expect(frame.style.width).toBe('100%');
		expect(frame.style.aspectRatio).toBe('16 / 9');
		const buttons = container.querySelectorAll('button');
		expect(buttons[1].getAttribute('aria-checked')).toBe('true');
		await act(async () => buttons[0].click());
		expect(frame.style.width).toBe('390px');
		expect(frame.style.height).toBe('844px');
		expect(frame.style.alignSelf).toBe('center');
		await act(async () => buttons[1].click());
		expect(frame.style.width).toBe('100%');
		expect(frame.style.aspectRatio).toBe('16 / 9');
	});

	it('posts the current draft on iframe load to the preview origin', async () => {
		await act(async () => root.render(<DisplayPreview {...props} />));
		const frame = container.querySelector('iframe')!;
		const postMessage = vi.spyOn(frame.contentWindow!, 'postMessage').mockImplementation(() => {});
		await act(async () => frame.dispatchEvent(new Event('load')));
		expect(DISPLAY_PREVIEW_MESSAGE_TYPE).toBe('display.preview.template');
		expect(postMessage).toHaveBeenCalledWith(
			{ wcpos: 1, type: 'display.preview.template', content: props.content },
			'https://example.test'
		);
	});

	it('debounces draft changes for 300 ms and clears pending posts on unmount', async () => {
		await act(async () => root.render(<DisplayPreview {...props} />));
		const postMessage = vi
			.spyOn(container.querySelector('iframe')!.contentWindow!, 'postMessage')
			.mockImplementation(() => {});
		await act(async () => root.render(<DisplayPreview {...props} content="First" />));
		await act(async () => vi.advanceTimersByTime(200));
		await act(async () => root.render(<DisplayPreview {...props} content="Latest" />));
		await act(async () => vi.advanceTimersByTime(299));
		expect(postMessage).not.toHaveBeenCalled();
		await act(async () => vi.advanceTimersByTime(1));
		expect(postMessage).toHaveBeenCalledExactlyOnceWith(
			{ wcpos: 1, type: 'display.preview.template', content: 'Latest' },
			'https://example.test'
		);
		await act(async () => root.render(<DisplayPreview {...props} content="Pending" />));
		await act(async () => root.render(null));
		await act(async () => vi.advanceTimersByTime(300));
		expect(postMessage).toHaveBeenCalledTimes(1);
	});

	it('shows the Pro notice and documentation link without iframe or controls', async () => {
		await act(async () => root.render(<DisplayPreview {...props} isProActive={false} />));
		expect(container.textContent).toContain('Display templates need WCPOS Pro to preview.');
		expect(container.querySelector('a')?.getAttribute('href')).toBe(
			'https://docs.wcpos.com/customer-display'
		);
		expect(container.querySelector('a')?.textContent).toBe('Learn more');
		expect(container.querySelector('iframe, select, button')).toBeNull();
	});
});
