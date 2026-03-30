import { test, expect } from '../fixtures/admin';

test.describe('Settings Page', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		await adminPage.waitForLoadState('networkidle');
	});

	test('Settings page loads without PHP errors', async ({ adminPage }) => {
		await expect(adminPage.locator('body')).not.toContainText('Fatal error');
		await expect(adminPage.locator('body')).not.toContainText('Warning:');
		await expect(adminPage.locator('#woocommerce-pos-settings')).toBeVisible();
	});

	test('Settings React app loads', async ({ adminPage }) => {
		// JS error fallback should not be visible
		await expect(adminPage.locator('#woocommerce-pos-js-error')).not.toBeVisible({ timeout: 15000 });
	});

	test('Navigation sidebar is visible', async ({ adminPage }) => {
		// Wait for React app to render the aside nav
		const nav = adminPage.locator('aside');

		// Debug: dump page state if aside isn't immediately found
		try {
			await expect(nav).toBeVisible({ timeout: 5000 });
		} catch {
			const settingsDiv = adminPage.locator('#woocommerce-pos-settings');
			const innerHTML = await settingsDiv.innerHTML().catch(() => 'NOT FOUND');
			console.log('=== DEBUG: #woocommerce-pos-settings innerHTML ===');
			console.log(innerHTML.substring(0, 2000));
			console.log('=== DEBUG: Console errors ===');
			const logs = await adminPage.evaluate(() => {
				return (window as any).__console_errors || [];
			});
			console.log(JSON.stringify(logs));
			// Now wait with original timeout
			await expect(nav).toBeVisible({ timeout: 10000 });
		}

		// All five nav links should be present
		await expect(nav.getByText('General')).toBeVisible();
		await expect(nav.getByText('Checkout')).toBeVisible();
		await expect(nav.getByText('Access')).toBeVisible();
		await expect(nav.getByText('Sessions')).toBeVisible();
		await expect(nav.getByText('License')).toBeVisible();
	});

	test('Defaults to General page', async ({ adminPage }) => {
		// The index route redirects to #/general
		await adminPage.waitForTimeout(2000);
		expect(adminPage.url()).toContain('#/general');
	});
});
