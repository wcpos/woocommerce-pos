import { test, expect } from '../fixtures/admin';

test.describe('Checkout Settings', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings#/checkout');
		await adminPage.waitForLoadState('networkidle');
		await expect(adminPage.locator('aside')).toBeVisible({ timeout: 15000 });
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
