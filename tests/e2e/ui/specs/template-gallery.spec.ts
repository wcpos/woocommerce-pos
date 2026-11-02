import { readFile } from 'node:fs/promises';

import { expect, test } from '@playwright/test';

test('Halloween bats start in distinct animation phases', async ({ page }) => {
	const template = await readFile('templates/gallery/display-halloween.html', 'utf8');

	await page.emulateMedia({ reducedMotion: 'no-preference' });
	await page.setContent(template);

	const delays = await page
		.locator('.wcpos-idle--halloween .bat')
		.evaluateAll((bats) => bats.map((bat) => getComputedStyle(bat).animationDelay));

	expect(delays).toHaveLength(4);
	expect(new Set(delays).size).toBe(delays.length);
});
