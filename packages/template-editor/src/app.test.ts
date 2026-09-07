import { act, createElement } from 'react';

import { createRoot } from 'react-dom/client';
import { describe, expect, it } from 'vitest';

import {
	App,
	DISPLAY_STARTER_SHELL,
	getDefaultDoc,
	getEditorLayoutStyle,
	STARTER_SHELLS,
	getThermalStarterShell,
} from './app';

import type { EditorConfig } from './types';

const config: EditorConfig = {
	type: 'display',
	displayStarter: null,
	isProActive: true,
	displayPreviewUrl: 'https://example.test/wcpos-display/',
	fieldSchema: {},
	sampleData: {},
	engine: 'logicless',
	paperWidth: null,
	templateId: 123,
	previewUrl: 'https://example.test/wp-json/wcpos/v2/templates/123/preview',
	postContent: '',
	hasPosOrders: false,
};

describe('display editor', () => {
	it('uses saved content before the active display starter', () => {
		expect(getDefaultDoc({ ...config, postContent: 'Saved', displayStarter: 'Active' })).toBe(
			'Saved'
		);
	});

	it('uses the active display starter before the shell', () => {
		expect(getDefaultDoc({ ...config, displayStarter: 'Active' })).toBe('Active');
	});

	it('falls back to a shell containing all seven display states in order', () => {
		expect(getDefaultDoc(config)).toBe(DISPLAY_STARTER_SHELL);
		const container = document.createElement('div');
		container.innerHTML = getDefaultDoc(config);
		expect(
			Array.from(
				container.querySelectorAll('section'),
				(section) => section.dataset.wcposState
			)
		).toEqual([
			'idle',
			'cart.empty',
			'cart',
			'payment.started',
			'payment.approved',
			'payment.declined',
			'payment.complete',
		]);
		expect(DISPLAY_STARTER_SHELL.split('\n').length).toBeLessThan(60);
	});

	it.each(['logicless', 'thermal', 'legacy-php'] as const)(
		'keeps the %s receipt defaults and saved content',
		(engine) => {
			const receipt = {
				...config,
				type: 'receipt' as const,
				engine,
				displayStarter: 'Display',
			};
			expect(getDefaultDoc(receipt)).toBe(STARTER_SHELLS[engine]);
			expect(getDefaultDoc({ ...receipt, postContent: 'Saved' })).toBe('Saved');
		}
	);

	it('renders the display preview without receipt controls and ignores receipt metabox events', async () => {
		const container = document.createElement('div');
		document.body.appendChild(container);
		const root = createRoot(container);
		try {
			await act(async () => root.render(createElement(App, { config })));
			expect(container.querySelector('iframe')?.getAttribute('src')).toBe(
				'https://example.test/wcpos-display/?preview=cart&template=123'
			);
			expect(container.textContent).toContain('Customer display template.');
			expect(container.textContent).not.toContain('Sample Data');
			expect(container.textContent).not.toContain('Receipt Printer template');
			const editorContent = container.querySelector('.cm-content')?.textContent;
			expect(editorContent).toContain('data-wcpos-state');
			await act(async () => {
				window.dispatchEvent(
					new CustomEvent('wcposEngineChange', { detail: { engine: 'thermal' } })
				);
				window.dispatchEvent(
					new CustomEvent('wcposPaperWidthChange', { detail: { paperWidth: '58mm' } })
				);
			});
			expect(container.querySelector('.cm-content')?.textContent).toBe(editorContent);
			expect(container.textContent).not.toContain('Receipt Printer template');
			expect(container.querySelector('iframe')?.getAttribute('src')).toContain(
				'/wcpos-display/'
			);
		} finally {
			await act(async () => root.unmount());
			container.remove();
		}
	});
});

describe('template editor layout', () => {
	it('gives the editor row a definite bounded height so side panels scroll internally', () => {
		expect(getEditorLayoutStyle()).toEqual({
			height: 'calc(100vh - 320px)',
			minHeight: 440,
			maxHeight: 720,
		});
	});
});

describe('starter shells', () => {
	it('logicless starter uses formatted money keys and localized labels', () => {
		const shell = STARTER_SHELLS.logicless;
		// Money must use the formatted *_display companions, not the raw numeric keys.
		expect(shell).toContain('{{line_total_display}}');
		expect(shell).toContain('{{totals.total_incl_display}}');
		expect(shell).not.toContain('{{line_total_incl}}');
		expect(shell).not.toContain('{{totals.total_incl}}');
		// Labels come from the i18n payload, not hard-coded English.
		expect(shell).toContain('{{i18n.order}}');
		expect(shell).toContain('{{i18n.total}}');
		expect(shell).toContain('{{i18n.thank_you_purchase}}');
	});

	it('thermal starter uses formatted money keys and localized labels at every paper width', () => {
		for (const [paperWidth, expectedChars] of [
			['80mm', 48],
			['58mm', 32],
		] as const) {
			const shell = getThermalStarterShell(paperWidth);
			expect(shell).toContain(`paper-width="${expectedChars}"`);
			expect(shell).toContain('{{line_total_display}}');
			expect(shell).toContain('{{totals.total_incl_display}}');
			expect(shell).not.toContain('{{line_total_incl}}');
			expect(shell).not.toContain('{{totals.total_incl}}');
			expect(shell).toContain('{{i18n.order}}');
			expect(shell).toContain('{{i18n.total}}');
			expect(shell).toContain('{{i18n.thank_you_purchase}}');
		}
	});

	it('A4 starters use a full-page layout, not a fixed-width receipt-roll cage', () => {
		// logicless and legacy-php render full A4 in the browser print dialog —
		// they must use page padding, not a narrow centered max-width column.
		for (const shell of [STARTER_SHELLS.logicless, STARTER_SHELLS['legacy-php']]) {
			expect(shell).toMatch(/padding:\s*32px\s+36px\b/);
			expect(shell).not.toMatch(/max-width:\s*380px\b/);
		}
	});

	it('legacy-php starter renders from $receipt_data, not the WC_Order', () => {
		const shell = STARTER_SHELLS['legacy-php'];
		// Reads the canonical $receipt_data payload and formats money with wc_price().
		expect(shell).toContain('$receipt_data');
		expect(shell).toContain('wc_price(');
		// Labels come from the i18n array.
		expect(shell).toContain("$i18n['order']");
		expect(shell).toContain("$i18n['total']");
		expect(shell).toContain("$i18n['thank_you_purchase']");
		// Includes the print hook so the receipt actually prints.
		expect(shell).toContain("do_action( 'woocommerce_pos_receipt_head' )");
		// Does not call WC_Order methods directly.
		expect(shell).not.toContain('$order->');
	});
});
