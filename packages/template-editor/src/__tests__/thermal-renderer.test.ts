import { describe, expect, it } from 'vitest';

import { renderThermalPreview } from '@wcpos/thermal-utils';

describe('thermal renderer star columns', () => {
	it('allocates width="*" columns from remaining receipt columns', () => {
		const html = renderThermalPreview(
			'<receipt paper-width="42"><row><col width="*">Subtotal</col><col width="14" align="right">13,26 €</col></row></receipt>',
			{}
		);

		expect(html).toContain('flex: 0 0 28ch');
		expect(html).toContain('flex: 0 0 14ch');
	});

	it('splits remaining receipt columns across multiple star columns', () => {
		const html = renderThermalPreview(
			'<receipt paper-width="42"><row><col width="*">Left</col><col width="5">Qty</col><col width="*" align="right">Right</col></row></receipt>',
			{}
		);

		expect(html).toContain('flex: 0 0 18ch');
		expect(html).toContain('flex: 0 0 5ch');
		expect(html).toContain('flex: 0 0 19ch');
	});

	it('uses the receipt paper-width as the active CPL for star columns', () => {
		const html = renderThermalPreview(
			'<receipt paper-width="48"><row><col width="*">Subtotal</col><col width="14" align="right">13,26 €</col></row></receipt>',
			{}
		);

		expect(html).toContain('flex: 0 0 34ch');
		expect(html).toContain('flex: 0 0 14ch');
	});
});
