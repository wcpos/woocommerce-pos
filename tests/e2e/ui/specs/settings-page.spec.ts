import { test, expect } from '../fixtures/admin';

test.describe('Settings Page', () => {
	test.beforeEach(async ({ adminPage }) => {
		await adminPage.goto('/wp-admin/admin.php?page=woocommerce-pos-settings');
		await adminPage.waitForLoadState('domcontentloaded');
		await expect(adminPage.locator('#woocommerce-pos-settings')).toBeVisible({ timeout: 15000 });
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
		await expect(nav).toBeVisible({ timeout: 15000 });

		// All five core nav links should be present. Match by stable route
		// testids rather than translated labels, which change per locale.
		await expect(adminPage.getByTestId('settings-nav-general')).toBeVisible();
		await expect(adminPage.getByTestId('settings-nav-checkout')).toBeVisible();
		await expect(adminPage.getByTestId('settings-nav-access')).toBeVisible();
		await expect(adminPage.getByTestId('settings-nav-sessions')).toBeVisible();
		await expect(adminPage.getByTestId('settings-nav-license')).toBeVisible();
	});

	test('Defaults to General page', async ({ adminPage }) => {
		// The index route redirects to #/general
		await adminPage.waitForTimeout(2000);
		expect(adminPage.url()).toContain('#/general');
	});
});
