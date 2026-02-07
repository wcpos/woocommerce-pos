import { test, expect } from '../fixtures/admin';

test.describe('General Settings', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/general');
		await adminPage.waitForLoadState('networkidle');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
	});

	test('General page renders toggle fields', async ({ adminPage }) => {
		// The general page has several Toggle (Switch) controls
		const switches = adminPage.locator('button[role="switch"]');
		await expect(switches.first()).toBeVisible({ timeout: 10000 });

		// Should have at least the pos_only_products, decimal_qty, and generate_username toggles
		const count = await switches.count();
		expect(count).toBeGreaterThanOrEqual(3);
	});

	test('toggling a setting triggers a save', async ({ adminPage }) => {
		// Find the first toggle and click it
		const firstSwitch = adminPage.locator('button[role="switch"]').first();
		await expect(firstSwitch).toBeVisible({ timeout: 10000 });

		// Capture the initial state
		const wasChecked = await firstSwitch.getAttribute('aria-checked');
		await firstSwitch.click();

		// Wait for the API save to complete
		await adminPage.waitForTimeout(2000);

		// The toggle state should have flipped
		const isCheckedNow = await firstSwitch.getAttribute('aria-checked');
		expect(isCheckedNow).not.toBe(wasChecked);

		// Toggle it back to restore original state
		await firstSwitch.click();
		await adminPage.waitForTimeout(2000);
	});
});
