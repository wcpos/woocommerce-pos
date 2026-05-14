import { describe, expect, it } from 'vitest';

import { getEditorLayoutStyle, STARTER_SHELLS, getThermalStarterShell } from './app';

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
