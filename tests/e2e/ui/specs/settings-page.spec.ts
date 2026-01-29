import { test, expect } from '../fixtures/admin';

test.describe('Settings Page', () => {
	test.beforeEach(async ({ adminPage }) => {
		// Navigate to WCPOS settings page
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		// Wait for page to be ready
		await adminPage.waitForLoadState('networkidle');
	});

	test('Settings page loads without errors', async ({ adminPage }) => {
		// Check that the settings page loaded (no PHP errors)
		await expect(adminPage.locator('body')).not.toContainText('Fatal error');
		await expect(adminPage.locator('body')).not.toContainText('Warning:');
		
		// Check for settings container (the root div for React app)
		await expect(adminPage.locator('#woocommerce-pos-settings')).toBeVisible();
	});

	test('Settings page title is visible', async ({ adminPage }) => {
		// The React app renders a "Settings" title - wait for it
		// Look for the settings header or navigation
		const settingsTitle = adminPage.locator('text=Settings').first();
		await expect(settingsTitle).toBeVisible({ timeout: 10000 });
	});

	test('Settings tabs are visible', async ({ adminPage }) => {
		// The settings page has tabs - General, Checkout, Access, Sessions, License
		// Wait for the React app to render
		await adminPage.waitForTimeout(2000);
		
		// Check for at least one settings tab
		const generalTab = adminPage.locator('text=General').first();
		await expect(generalTab).toBeVisible({ timeout: 10000 });
		
		// Verify the settings container loaded (not the error fallback)
		await expect(adminPage.locator('#woocommerce-pos-js-error')).not.toBeVisible();
	});
});
