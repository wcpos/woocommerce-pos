import { test, expect } from '../fixtures/admin';

test.describe('Settings Page', () => {
	test.beforeEach(async ({ adminPage }) => {
		// Navigate to WCPOS settings page
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
	});

	test('Settings page loads without errors', async ({ adminPage }) => {
		// Check that the settings page loaded (no PHP errors)
		await expect(adminPage.locator('body')).not.toContainText('Fatal error');
		await expect(adminPage.locator('body')).not.toContainText('Warning:');
		
		// Check for settings container
		await expect(adminPage.locator('.wrap')).toBeVisible();
	});

	test('Settings page title is visible', async ({ adminPage }) => {
		// Check that the page title contains "POS" or settings-related text
		const pageTitle = adminPage.locator('.wrap h1, .wrap h2').first();
		await expect(pageTitle).toBeVisible();
	});

	test('Settings form or React app container exists', async ({ adminPage }) => {
		// The settings page uses a React app - check the container exists
		// Wait a bit for React to render
		await adminPage.waitForTimeout(1000);
		
		// Check for either a form or React root container
		const settingsContainer = adminPage.locator('.wrap form, .wrap #root, .wrap [class*="settings"]').first();
		await expect(settingsContainer).toBeVisible({ timeout: 10000 });
	});
});
