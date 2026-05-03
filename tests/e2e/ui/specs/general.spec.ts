import { test, expect } from '../fixtures/admin';

test.describe('General Settings', () => {
	test.beforeEach(async ({ adminPage }) => {
		// Set up response listener BEFORE navigation to avoid race conditions.
		// networkidle can fire before React mounts and starts its API calls,
		// so we explicitly wait for the settings response.
		const settingsLoaded = adminPage.waitForResponse(
			(resp) => resp.url().includes('wcpos/v1/settings/general') && resp.status() === 200,
			{ timeout: 30000 }
		);
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/general');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
		await settingsLoaded;
	});

	test('General page renders toggle fields', async ({ adminPage }) => {
		// The general page has several Toggle (Switch) controls
		const switches = adminPage.locator('button[role="switch"]');
		await expect(switches.first()).toBeVisible({ timeout: 10000 });

		// Should have at least the pos_only_products, decimal_qty, and generate_username toggles
		const count = await switches.count();
		expect(count).toBeGreaterThanOrEqual(3);
	});

	test('toggling a setting saves to the API and persists across reloads', async ({
		adminPage,
	}) => {
		const firstSwitch = adminPage.locator('button[role="switch"]').first();
		await expect(firstSwitch).toBeVisible({ timeout: 10000 });

		const wasChecked = await firstSwitch.getAttribute('aria-checked');

		// Toggle and wait for the POST. waitForResponse must be set up BEFORE
		// the click — clicking first races the listener attach against the
		// network request.
		const savedResponse = adminPage.waitForResponse(
			(resp) =>
				resp.url().includes('wcpos/v1/settings/general') &&
				resp.request().method() === 'POST',
			{ timeout: 15000 }
		);
		await firstSwitch.click();
		const postResp = await savedResponse;
		expect(postResp.status()).toBe(200);

		// UI reflects the new state.
		const isCheckedNow = await firstSwitch.getAttribute('aria-checked');
		expect(isCheckedNow).not.toBe(wasChecked);

		// Reload the page and confirm the new state actually persisted on
		// the server. Without this, a successful POST that wrote nothing
		// would still pass the previous version of this test.
		const settingsReloaded = adminPage.waitForResponse(
			(resp) => resp.url().includes('wcpos/v1/settings/general') && resp.status() === 200,
			{ timeout: 30000 }
		);
		await adminPage.reload();
		await settingsReloaded;
		const reloadedSwitch = adminPage.locator('button[role="switch"]').first();
		await expect(reloadedSwitch).toBeVisible({ timeout: 10000 });
		await expect(reloadedSwitch).toHaveAttribute('aria-checked', isCheckedNow ?? '');

		// Toggle back to restore original state and confirm the rollback save
		// also returned 200, so the next test starts from a clean baseline.
		const restoreResponse = adminPage.waitForResponse(
			(resp) =>
				resp.url().includes('wcpos/v1/settings/general') &&
				resp.request().method() === 'POST',
			{ timeout: 15000 }
		);
		await reloadedSwitch.click();
		const restoreResp = await restoreResponse;
		expect(restoreResp.status()).toBe(200);
		await expect(reloadedSwitch).toHaveAttribute('aria-checked', wasChecked ?? '');
	});
});
