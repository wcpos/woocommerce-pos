import { test, expect } from '../fixtures/admin';

test.describe('Checkout Settings', () => {
	test.beforeEach(async ({ adminPage }) => {
		// Set up response listener BEFORE navigation to avoid race conditions.
		// networkidle can fire before React mounts and starts its API calls,
		// so we explicitly wait for the settings response.
		const settingsLoaded = adminPage.waitForResponse(
			(resp) => resp.url().includes('wcpos/v1/settings/checkout') && resp.status() === 200,
			{ timeout: 30000 }
		);
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/checkout');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
		await settingsLoaded;
	});

	test('Checkout page renders toggle controls', async ({ adminPage }) => {
		// Checkout has toggles for admin_emails and customer_emails
		const switches = adminPage.locator('button[role="switch"]');
		await expect(switches.first()).toBeVisible({ timeout: 10000 });
	});

	test('Gateways table is visible', async ({ adminPage }) => {
		const table = adminPage.locator('table');
		await expect(table).toBeVisible({ timeout: 10000 });

		// Verify the expected column headers
		await expect(table.locator('th').getByText('Default')).toBeVisible();
		await expect(table.locator('th').getByText('Gateway')).toBeVisible();
		await expect(table.locator('th').getByText('Enabled')).toBeVisible();
	});

	test('Gateways table has at least one row', async ({ adminPage }) => {
		const table = adminPage.locator('table');
		await expect(table).toBeVisible({ timeout: 10000 });

		// Should have at least one gateway row in the tbody
		const rows = table.locator('tbody tr');
		const count = await rows.count();
		expect(count).toBeGreaterThanOrEqual(1);
	});
});
