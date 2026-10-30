import fs from 'node:fs';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

// Paul, 2026-10-30: a closure template shipped with an 8 px black band across the top. "It just
// wastes ink." A closure or report is printed on a mono laser at the end of every day; its paper
// carries rules and type, never bands or fills. This pins that for every HTML closure and report
// template in the gallery.
const gallery = path.resolve(__dirname, '../../../templates/gallery');
const papers = fs
	.readdirSync(gallery)
	.filter((file) => /^(closure|report)-.*\.html$/.test(file));

describe.each(papers)('%s', (file) => {
	const html = fs.readFileSync(path.join(gallery, file), 'utf8');

	it('prints no bands: every border is 2 px or thinner', () => {
		const thick = html.match(/border(?:-(?:top|bottom|left|right))?\s*:\s*([3-9]|\d{2,})px/g);
		expect(thick ?? []).toEqual([]);
	});

	it('prints no fills: no background on any element', () => {
		expect(html).not.toMatch(/background(?:-color)?\s*:/);
		expect(html).not.toMatch(/print-color-adjust/);
	});
});
