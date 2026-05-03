import { test, expect } from '../fixtures/admin';

test.describe('License Settings', () => {
	test.beforeEach(async ({ adminPage }) => {
		const settingsLoaded = adminPage.waitForResponse(
			(resp) => resp.url().includes('wcpos/v1/settings/license') && resp.status() === 200,
			{ timeout: 30000 }
		);
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/license');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
		await settingsLoaded;
	});

	test('renders the upgrade CTA when no license is registered', async ({ adminPage }) => {
		// Free plugin in CI has no Pro license registered, so the page renders
		// the upgrade CTA. This proves the screen mounted past the loading
		// state — without it the screen would be blank when the license API
		// returns no instance.
		await expect(adminPage.getByText('Upgrade to Pro')).toBeVisible({ timeout: 10000 });
	});

	test('upgrade CTA links point at the WCPOS Pro page', async ({ adminPage }) => {
		// The CTA must link to the marketing site so the upgrade path works
		// for users; a regression here breaks the entire revenue funnel.
		const proLinks = adminPage.locator('a[href="https://wcpos.com/pro"]');
		await expect(proLinks.first()).toBeVisible({ timeout: 10000 });
		expect(await proLinks.count()).toBeGreaterThanOrEqual(1);
	});
});
