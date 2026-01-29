import { test, expect } from '../fixtures/admin';

test.describe('Settings Page', () => {
	test.beforeEach(async ({ adminPage }) => {
		// Navigate to WCPOS settings page
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		// Wait for page to be ready
		await adminPage.waitForLoadState('networkidle');
	});

	test('Settings page loads without PHP errors', async ({ adminPage }) => {
		// Check that the settings page loaded (no PHP errors)
		await expect(adminPage.locator('body')).not.toContainText('Fatal error');
		await expect(adminPage.locator('body')).not.toContainText('Warning:');
		
		// Check for settings container (the root div for React app)
		await expect(adminPage.locator('#woocommerce-pos-settings')).toBeVisible();
	});

	test('Settings React app loads', async ({ adminPage }) => {
		// Wait for React app to mount and render tabs
		// The app should show "Settings" title and navigation tabs
		await adminPage.waitForTimeout(2000);
		
		// Verify the React app loaded (not the error fallback)
		await expect(adminPage.locator('#woocommerce-pos-js-error')).not.toBeVisible({ timeout: 10000 });
	});

	test('Settings tabs are visible', async ({ adminPage }) => {
		// The settings page has tabs - General, Checkout, Access, Sessions, License
		// Wait for the React app to render
		await adminPage.waitForTimeout(2000);
		
		// Check for the General tab (first tab)
		const generalTab = adminPage.locator('text=General').first();
		await expect(generalTab).toBeVisible({ timeout: 10000 });
	});
});
