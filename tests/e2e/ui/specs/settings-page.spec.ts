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

	test('General settings tab is accessible', async ({ adminPage }) => {
		// Look for settings tabs or sections
		const generalSection = adminPage.locator('text=General');
		await expect(generalSection.first()).toBeVisible();
	});

	test('Checkout settings tab is accessible', async ({ adminPage }) => {
		// Look for checkout settings
		const checkoutSection = adminPage.locator('text=Checkout');
		await expect(checkoutSection.first()).toBeVisible();
	});

	test('Access settings tab is accessible', async ({ adminPage }) => {
		// Look for access settings
		const accessSection = adminPage.locator('text=Access');
		await expect(accessSection.first()).toBeVisible();
	});
});
